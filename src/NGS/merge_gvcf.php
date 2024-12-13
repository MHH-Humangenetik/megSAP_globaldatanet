<?php 
/** 
	@page merge_gvcf
*/

require_once(dirname($_SERVER['SCRIPT_FILENAME'])."/../Common/all.php");

error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);

// parse command line arguments
$parser = new ToolBase("merge_gvcf", "Merge multiple gVCF files using GATK CombineGVCFs");
$parser->addInfileArray("gvcfs", "List of gVCF files which should be merged (bgzipped and sorted).", false);
$parser->addStringArray("status", "List of affected status of the input samples (gVCFs) - can be 'affected' or 'control'.", false);
$parser->addEnum("mode", "Mode.", false, ["longread", "dragen"]);
$parser->addOutfile("out", "Output VCF files containing calls of the merged gVCFs.", false);

//optional
$parser->addEnum("analysis_type", "Type of multisample analysis.", true, array("GERMLINE_MULTISAMPLE", "GERMLINE_TRIO"), "GERMLINE_MULTISAMPLE");
$parser->addInt("threads", "The maximum number of threads used.", true, 2);
$parser->addString("build", "The genome build to use.", true, "GRCh38");
extract($parser->parse($argv));

//init
$genome = genome_fasta($build);
$gvcf_out = substr($out, 0, -6)."gvcf.gz";
if (count($gvcfs) < 2) trigger_error("At least two gVCF files have to be provided!", E_USER_ERROR);
if (count($gvcfs) != count($status)) trigger_error("List of gVCF files and statuses has to be the same.", E_USER_ERROR);
$temp_folder = $parser->tempFolder("merge_gvcf_pid_".getmypid()."_split_");

//check input status
foreach($status as $stat)
{
	$valid = array("affected", "control");
	if (!in_array($stat, $valid))
	{
		trigger_error("Invalid status '$stat' given. Valid are '".implode("', '", $valid)."'", E_USER_ERROR);
	}
}

//get chr regions and sample order
$chr_regions = array();
$sample_order = array();
$first_file = true;
foreach($gvcfs as $gvcf)
{
	//read header from each file
	$gvcf_fh = gzopen2($gvcf, "r");

	while(!gzeof($gvcf_fh))
	{
		$line = trim(gzgets($gvcf_fh));
		//skip empty lines
		if (trim($line) == "") continue;
		//stop when reaching variant area
		if ($line[0]!="#") break;
		//parse contig lines (only done for the first file)
		if ($first_file && starts_with($line, "##contig="))
		{
			$chr = trim(explode(",", explode("ID=", $line, 2)[1], 2)[0]);
			$length = (int) explode(">", explode("length=", $line, 2)[1], 2)[0];
			$skip_chr = false;
			if ($mode=="dragen")
			{
				if (starts_with($chr, "chrUn")) $skip_chr = true;
				if (ends_with($chr, "_random")) $skip_chr = true;
				if ($chr=="chrMT" || $chr=="chrEBV") $skip_chr = true;
			}
				
			if (!$skip_chr) $chr_regions[] = array($chr, $length);
		}
		//extract sample name
		if (starts_with($line, "#CHROM"))
		{
			$sample_name = explode("\t", $line);
			$sample_order[] = trim(end($sample_name));
		}
	}
	$first_file = false;
}


//create jobs to split up input gVCFs
$gvcf_file_prefix = array();
$jobs_split_gvcf = array();
$jobs_index_gvcf =array();
foreach($gvcfs as $gvcf)
{
	$gvcf_file_prefix[$gvcf] = random_string(8)."_".basename($gvcf);
	foreach($chr_regions as list($chr, $length))
	{
		$tmp_gvcf_file_name = $temp_folder."/".$gvcf_file_prefix[$gvcf]."_".$chr.".gvcf.gz";

		$jobs_split_gvcf[] = array("Split_gVCF_{$chr}", "tabix -h {$gvcf} {$chr}:1-{$length} | bgzip -c > {$tmp_gvcf_file_name}");
		$jobs_index_gvcf[] = array("Index_gVCF_{$chr}", "tabix -f -p vcf $tmp_gvcf_file_name");
	}
}

//split and index inputs in parallel
$parser->execParallel($jobs_split_gvcf, $threads, true, true, false);
$parser->execParallel($jobs_index_gvcf, $threads);

//create job list
$jobs_combine_gvcf = array();
//temp folder for output
$temp_folder_out = $parser->tempFolder("merge_gvcf_pid_".getmypid()."_out_");

foreach($chr_regions as list($chr, $length))
{
	$job_name = "CombineGVCFs_{$chr}";
	$args = array();
	$args[] = "CombineGVCFs";
	$args[] = "-R {$genome}";
	$args[] = "-O {$temp_folder_out}/{$chr}.gvcf.gz";
	$args[] = "--seconds-between-progress-updates 3600"; //only update progress once every hour to keep log-file smaller
	foreach ($gvcfs as $gvcf) 
	{
		$args[] = "--variant ".$temp_folder."/".$gvcf_file_prefix[$gvcf]."_".$chr.".gvcf.gz";
	}

	//special handling of chrY: takes much longer so start with it
	$command = $parser->execApptainer("gatk", "gatk", implode(" ", $args), [$genome], [], true);
	if ($chr == "chrY" || $chr == "Y")
	{
		array_unshift($jobs_combine_gvcf, array($job_name, $command));
	}
	else
	{
		$jobs_combine_gvcf[] = array($job_name, $command);
	}
	
}

// run genotype calling for every chromosome separately
$parser->execParallel($jobs_combine_gvcf, $threads);

//GenotypeGVCFs
$jobs_call_genotypes = array();
foreach($chr_regions as list($chr, $length))
{
	$job_name = "GenotypeGVCFs_{$chr}";
	$args = array();
	$args[] = "GenotypeGVCFs";
	$args[] = "-R {$genome}";
	$args[] = "-V {$temp_folder_out}/{$chr}.gvcf.gz";
	$args[] = "-O {$temp_folder_out}/{$chr}.vcf.gz";
	$args[] = "--call-genotypes";
	$args[] = "--seconds-between-progress-updates 3600"; //only update progress once every hour to keep log-file smaller
	if ($mode=="longread") $args[] = "--standard-min-confidence-threshold-for-calling 5"; //decrease threshold in longread-mode to improve de-novo calling 

	$command = $parser->execApptainer("gatk", "gatk", implode(" ", $args), [$genome], [], true);
	$jobs_call_genotypes[] = array($job_name, $command);
}
// run genotype calling for every chromosome separately
$parser->execParallel($jobs_call_genotypes, $threads);


//merge (g)vcfs to single file
$chr_multisample_gvcfs = array();
$chr_multisample_vcfs = array();
foreach($chr_regions as list($chr, $length))
{
	$chr_multisample_gvcfs[] = "{$temp_folder_out}/{$chr}.gvcf.gz";
	$chr_multisample_vcfs[] = "{$temp_folder_out}/{$chr}.vcf.gz";
}

//merge gVCFs
if ($mode=="longread")
{
	$pipeline = array();
	$pipeline[] = ["", $parser->execApptainer("ngs-bits", "VcfMerge", "-in ".implode(" ", $chr_multisample_gvcfs), [], [], true)];
	$pipeline[] = ["", $parser->execApptainer("ngs-bits", "VcfExtractSamples", "-samples ".implode(",", $sample_order), [], [], true)];
	$pipeline[] = array("bgzip", "-c > {$gvcf_out}", false);
	$parser->execPipeline($pipeline, "Merge gVCF");
	$parser->exec("tabix", "-f -p vcf {$gvcf_out}");
}

//merge VCFs
$tmp_vcf = $parser->tempFile(".vcf.gz");
$pipeline = array();
$pipeline[] = ["", $parser->execApptainer("ngs-bits", "VcfMerge", "-in ".implode(" ", $chr_multisample_vcfs), [], [], true)];
$pipeline[] = ["", $parser->execApptainer("ngs-bits", "VcfExtractSamples", "-samples ".implode(",", $sample_order), [], [], true)];
$pipeline[] = array("bgzip", "-c > {$tmp_vcf}", false);
$parser->execPipeline($pipeline, "Merge VCF");

//post-processing 
$pipeline = array();
//stream vcf.gz
$pipeline[] = array("zcat", $tmp_vcf);

//filter variants according to variant quality>5
$pipeline[] = ["", $parser->execApptainer("ngs-bits", "VcfFilter", "-qual 5 -ref $genome", [$genome], [], true)];

//split complex variants to primitives
//this step has to be performed before VcfBreakMulti - otherwise mulitallelic variants that contain both 'hom' and 'het' genotypes fail - see NA12878 amplicon test chr2:215632236-215632276
$pipeline[] = ["", $parser->execApptainer("vcflib", "vcfallelicprimitives", "-kg", [], [], true)];

//split multi-allelic variants
$pipeline[] = ["", $parser->execApptainer("ngs-bits", "VcfBreakMulti", "-no_errors", [], [], true)];
//remove invalid variants
$pipeline[] = ["", $parser->execApptainer("ngs-bits", "VcfFilter", "-remove_invalid -ref $genome", [$genome], [], true)];

//normalize all variants and align INDELs to the left
$pipeline[] = ["", $parser->execApptainer("ngs-bits", "VcfLeftNormalize", "-stream -ref $genome", [$genome], [], true)];

//sort variants by genomic position
$pipeline[] = ["", $parser->execApptainer("ngs-bits", "VcfStreamSort", "", [], [], true)];

//fix error in VCF file and strip unneeded information
$uncompressed_vcf = $parser->tempFile(".vcf");
$args = [];
if ($mode=="longread") $args[] = "--longread_mode";
if ($mode=="dragen") $args[] = "--dragen_mode";
$args[] = "> {$uncompressed_vcf}";
$pipeline[] = array("php ".repository_basedir()."/src/NGS/vcf_fix.php", implode(" ", $args), false);

//execute post-processing pipeline
$parser->execPipeline($pipeline, "merge_gvcf post processing");

//add name/pipeline info to VCF header
if ($mode=="longread")
{
	$vcf = Matrix::fromTSV($uncompressed_vcf);
	$comments = $vcf->getComments();
	$comments[] = "#reference={$genome}\n";
	$comments[] = "#ANALYSISTYPE={$analysis_type}\n";
	$comments[] = "#PIPELINE=".repository_revision(true)."\n";
	//get sample names
	$samples = array_slice($vcf->getHeaders(), -count($gvcfs));
	for ($i=0; $i < count($gvcfs); $i++) 
	{ 
		$comments[] = gsvar_sample_header($samples[$i], array("DiseaseStatus"=>$status[$i]), "#", "");
	}
	$vcf->setComments($comments);
	$vcf->toTSV($uncompressed_vcf);
}

//create zip + idx
$parser->exec("bgzip", "-@ {$threads} -c $uncompressed_vcf > $out", false);
//index output file
$parser->exec("tabix", "-f -p vcf $out", false); //no output logging, because Toolbase::extractVersion() does not return

?>
