<?php 
/** 
	@page vcf2gsvar
*/

require_once(dirname($_SERVER['SCRIPT_FILENAME'])."/../Common/all.php");

error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);

$parser = new ToolBase("vcf2gsvar", "Converts an annotated VCF file from freebayes to a GSvar file.");
$parser->addInfile("in",  "Input file in VCF format.", false);
$parser->addOutfile("out", "Output file in GSvar format.", false);
//optional
$parser->addEnum("genotype_mode", "Genotype handling mode.", true, array("single", "multi", "skip"), "single");
$parser->addFlag("updown", "Don't discard up- or downstream annotations (5000 bases around genes).");
$parser->addFlag("wgs", "Enables WGS mode: MODIFIER variants with a AF>2% are skipped to reduce the number of variants to a manageable size.");
extract($parser->parse($argv));

//skip common MODIFIER variants in WGS mode
function skip_in_wgs_mode($chr, $coding_and_splicing_details, $gnomad, $clinvar, $hgmd, $ngsd_clas)
{	
	//don't skip mito variants
	if ($chr=='chrMT') return false;
	
	//don't skip exonic/splicing variants
	if (contains($coding_and_splicing_details, ":LOW:") || contains($coding_and_splicing_details, ":MODERATE:") || contains($coding_and_splicing_details, ":HIGH:")) return false;
	
	//don't skip variants annotated to be (likely) pathogenic
	if (contains($hgmd, "CLASS=DM") || (contains($clinvar, "pathogenic") && !contains($clinvar, "conflicting"))) return false;	
	
	//don't skip variants of class 4/5/M in NGSD
	if ($ngsd_clas=='4' || $ngsd_clas=='5'|| $ngsd_clas=='M') return false;
	
	//skip common variants >2%AF
	if ($gnomad!="" && $gnomad>0.02) return true;
	
	return false; //non-exonic but rare
}

//get index of columnn in QSC header.
function index_of($cols, $name, $label)
{
	$index = array_search($name, $cols);
	if ($index===FALSE)
	{
		trigger_error("Could not find column '$name' in annotation field '{$label}'. Valid column names are: ".implode(", ", array_values($cols)), E_USER_ERROR);
	}
	return $index;
}

//translate value (and throw error if not valid)
function translate($error_name, $value, $dict)
{
	$value = trim($value);
	
	if (!isset($dict[$value]))
	{
		trigger_error("Cannot translate value '{$value}' for '{$error_name}'. Valid terms are: '".implode("','", array_keys($dict)), E_USER_ERROR);
	}
	
	return $dict[$value];
}

//collapse several values to a single value
function collapse(&$tag, $error_name, $values, $mode, $decimal_places = null)
{
	for($i=0; $i<count($values); ++$i)
	{
		$v = trim($values[$i]);
		if (!is_null($decimal_places) && $v!="")
		{
			if (!is_numeric($v))
			{
				trigger_error("Invalid numeric value '{$v}' in mode '{$mode}' while collapsing '{$error_name}' in variant '{$tag}'!", E_USER_ERROR);
			}
			$v = number_format($v, $decimal_places, ".", "");
			if ($values[$i]>0 && $v==0)
			{
				$v = substr($v, 0, -1)."1";
			}
		}
		$values[$i] = $v;
	}

	if ($mode=="one")
	{
		$values = array_unique($values);
		if (count($values)>1)
		{
			trigger_error("Several values '".implode("','", $values)."' in mode '{$mode}' while collapsing '{$error_name}' in variant '{$tag}'!", E_USER_ERROR);
		}
		else if (count($values)==0)
		{
			return "";
		}
		return $values[0];
	}
	else if ($mode=="max")
	{
		if (count($values)==0) return "";
		return max($values);
	}
	else if ($mode=="unique")
	{
		return array_unique($values);
	}
	else
	{
		trigger_error("Invalid mode '{$mode}' while collapsing '{$error_name}' in variant '{$tag}'!", E_USER_ERROR);
	}
}

//write header line
function write_header_line($handle, $column_desc, $filter_desc)
{
	//add optional header depending on annotation
	global $skip_ngsd_som;
	global $column_desc_ngsd_som;
	if (!$skip_ngsd_som) $column_desc = array_merge($column_desc, $column_desc_ngsd_som);
	global $skip_ngsd;
	global $column_desc_ngsd;
	if (!$skip_ngsd) $column_desc = array_merge($column_desc, $column_desc_ngsd);
	global $skip_cosmic_cmc;
	global $colum_desc_cosmic_cmc;
	if (!$skip_cosmic_cmc) $column_desc = array_merge($column_desc, $colum_desc_cosmic_cmc);
	global $skip_cancerhotspots;
	global $column_desc_cancerhotspots;
	if(!$skip_cancerhotspots) $column_desc = array_merge($column_desc, $column_desc_cancerhotspots);
	
	//write out header
	foreach($column_desc as $entry)
	{
		fwrite($handle, "##DESCRIPTION=".$entry[0]."=".$entry[1]."\n");
	}
	
	foreach($filter_desc as $entry)
	{
		fwrite($handle, "##FILTER=".$entry[0]."=".$entry[1]."\n");
	}

	fwrite($handle, "#chr\tstart\tend\tref\tobs");
	foreach($column_desc as $entry)
	{
		fwrite($handle, "\t".$entry[0]);
	}
	fwrite($handle, "\n");
}

//load replaced/removed Pfam IDs
function load_pfam_replacements()
{
	$pfam_filepath = repository_basedir()."/data/misc/pfam_replacements.tsv";
	if (!is_readable($pfam_filepath))
	{
		trigger_error("Pfam replacement file '$pfam_filepath' is not readable!", E_USER_ERROR);
	}
	$pfam_list = file($pfam_filepath);
	$pfam_replacements = array();
	foreach($pfam_list as $line)
	{
		// ignore comments
		if (starts_with($line, '#'))
		{
			continue;
		}

		$split_line = explode("\t",$line);
		if (count($split_line) < 2)
		{
			trigger_error("Error parsing Pfam file '$pfam_filepath'!", E_USER_ERROR);
		}
		$pfam_replacements[trim($split_line[0])] = trim($split_line[1]);
	}
	return $pfam_replacements;
}
$pfam_replacements = load_pfam_replacements();

//load Pfam description
function load_pfam_description()
{
	$pfam_filepath = repository_basedir()."/data/misc/pfam_description.tsv";
	if (!is_readable($pfam_filepath))
	{
		trigger_error("Pfam description file '$pfam_filepath' is not readable!", E_USER_ERROR);
	}
	$pfam_list = file($pfam_filepath);
	$pfam_description = array();
	foreach($pfam_list as $line)
	{
		// ignore comments
		if (starts_with($line, '#'))
		{
			continue;
		}

		$split_line = explode("\t",$line);
		if (count($split_line) < 2)
		{
			trigger_error("Error parsing Pfam file '$pfam_filepath'!", E_USER_ERROR);
		}
		$description_string = trim($split_line[1]);
		$description_string = strtr($description_string, array(":" => " ", "," => "", "[" => "(", "]" => ")"));
		$pfam_description[trim($split_line[0])] = $description_string;
	}
	return $pfam_description;
}
$pfam_description = load_pfam_description();

//load HGNC data
function load_hgnc_db()
{
	$output = array();
	
	//parse approved genes
	$filename = get_path("data_folder")."/dbs/HGNC/hgnc_complete_set.tsv";
	foreach (file($filename) as $line)
	{
		$line = trim($line);
		if ($line=="" || starts_with($line, "hgnc_id\t")) continue;
		list($id, $symbol, $name, $locus_group, $locus_type, $status) = explode("\t", $line);
		
		$id = trim(strtr($id, array("HGNC:"=>"")));
		$symbol = trim($symbol);
		
		$status = trim($status);
		if ($status!="Approved") continue;
		
		$output[$id] = $symbol;
	}
	
	//try to replace withdrawn symbols by current symbols
	$withdrawn = array();
	$filename = get_path("data_folder")."/dbs/HGNC/hgnc_withdrawn.tsv";
	foreach (file($filename) as $line)
	{
		$line = nl_trim($line);
		if ($line=="" || starts_with($line, "HGNC_ID\t")) continue;
		list($id, $status, $symbol, $merged_into) = explode("\t", $line);
		
		//skip all but approved merged
		$status = trim($status);
		if ($status!="Merged/Split") continue;		
		if(contains($merged_into, ",")) continue; 
		if(!contains($merged_into, "Approved")) continue;
		
		$id = trim(strtr($id, array("HGNC:"=>"")));
		$id_new = explode("|", $merged_into)[0];
		$id_new = trim(strtr($id_new, array("HGNC:"=>"")));
		$withdrawn[$id] = $id_new;
	}
	
	foreach($withdrawn as $id_old => $new_id)
	{
		if(isset($output[$new_id]))
		{
			//print "replaced: $id_old > $new_id (".$output[$new_id].")\n";
			$output[$id_old] = $output[$new_id];
		}
	}
	
	return $output;
}

$hgnc = load_hgnc_db();

//write column descriptions
$column_desc = array(
	array("filter", "Annotations for filtering and ranking variants."),
	array("quality", "Quality parameters - variant quality (QUAL), depth (DP), quality divided by depth (QD), allele frequency (AF), mean mapping quality of alternate allele (MQM), probability of strand bias for alternate bases as phred score (SAP), probability of allele ballance as phred score (ABP)"),
	array("gene", "Affected gene list (comma-separated)."),
	array("variant_type", "Variant type."),
	array("coding_and_splicing", "Coding and splicing details (Gene, ENST number, type, impact, exon/intron number, HGVS.c, HGVS.p, Pfam domain)."),
	array("regulatory", "Regulatory consequence details."),
	array("OMIM", "OMIM database annotation."),
	array("ClinVar", "ClinVar database annotation."),
	array("HGMD", "HGMD database annotation."),
	array("RepeatMasker", "RepeatMasker annotation."),
	array("dbSNP", "Identifier in dbSNP database."),
	array("gnomAD", "Allele frequency in gnomAD project."),
	array("gnomAD_sub", "Sub-population allele frequenciens (AFR,AMR,EAS,NFE,SAS) in gnomAD project."),
	array("gnomAD_hom_hemi", "Homoyzgous counts and hemizygous counts of gnomAD project (genome data)."),
	array("gnomAD_het", "Heterozygous counts of the gnomAD project (genome data)."),
	array("gnomAD_wt", "Wildtype counts of the gnomAD project (genome data)."),
	array("phyloP", "phyloP (100way vertebrate) annotation. Deleterious threshold > 1.6."),
	array("Sift", "Sift effect prediction and score for each transcript: D=damaging, T=tolerated."),
	array("PolyPhen", "PolyPhen (humVar) effect prediction and score for each transcript: D=probably damaging, P=possibly damaging, B=benign."),
	array("CADD", "CADD pathogenicity prediction scores (scaled phred-like). Deleterious threshold > 10-20."),
	array("REVEL", "REVEL pathogenicity prediction score. Deleterious threshold > 0.5."),
	array("MaxEntScan", "MaxEntScan splicing prediction (reference bases score/alternate bases score)."),
	array("COSMIC", "COSMIC somatic variant database anntotation."),
	array("SpliceAI", "SpliceAI prediction of splice-site variations. Probability of the variant being splice-altering (range from 0-1). The score is the maximum value of acceptor/donor gain/loss of all effected genes."),
	array("PubMed", "PubMed ids to publications on the given variant.")
);

// optional NGSD somatic header description if vcf contains NGSD somatic information
$column_desc_ngsd_som = array(
	array("NGSD_som_c", "Somatic variant count in the NGSD."),
	array("NGSD_som_p", "Project names of project containing this somatic variant in the NGSD."),
	array("NGSD_som_vicc_interpretation", "Somatic variant interpretation according VICC standard in the NGSD."),
	array("NGSD_som_vicc_comment", "Somatic VICC interpretation comment in the NGSD.")
);

// optional NGSD header description if vcf contains NGSD information
$column_desc_ngsd = array(
	array("NGSD_hom", "Homozygous variant count in NGSD."),
	array("NGSD_het", "Heterozygous variant count in NGSD."),
	array("NGSD_group", "Homozygous / heterozygous variant count in NGSD with the same disease group."),
	array("classification", "Classification from the NGSD."),
	array("classification_comment", "Classification comment from the NGSD."),
	array("validation", "Validation information from the NGSD. Validation results of other samples are listed in brackets!"),
	array("comment", "Variant comments from the NGSD."),
	array("gene_info", "Gene information from NGSD (inheritance mode, gnomAD o/e scores).")
);

//optional COSMIC CMC header description if vcf contains COSMIC CMC information
$colum_desc_cosmic_cmc = array(
	array("CMC_genes", "Gene symbol from COSMIC Cancer Mutation Census (CMC)."),
	array("CMC_MUTATION_ID", "COSV identifier of variant from COSMIC Cancer Mutation Census (CMC)."),
	array("CMC_disease", "diseases with > 1% of samples mutated where disease = Primary site(tissue) / Primary histology / Sub-histology = Samples mutated / Samples tested = Frequency from COSMIC Cancer Mutation Census (CMC)."),
	array("CMC_DNDS_ratio", "diseases with significant dn/ds ratio (q-value < 5%) from COSMIC Cancer Mutation Census (CMC)."),
	array("CMC_mutation_significance", "Significance tier of the variant from COSMIC Cancer Mutation Census (CMC).")
);

//optional CANCERHOTSPOTS header description if vcf contains Cancerhotspots.org information
$column_desc_cancerhotspots = array(
	array("CANCERHOTSPOTS_AA_CHANGE", "Amino acid change as in original cancerhotspots.org file"),
	array("CANCERHOTSPOTS_TOTAL_MUT", "Total mutation count in cancerhotspots.org at certain amino acid position."),
	array("CANCERHOTSPOTS_ALT_COUNT", "Count of specific amino acid alteration at same position in cancerhotspots.org.")
);


if ($genotype_mode=="single")
{
	array_unshift($column_desc, array("genotype", "Genotype of variant in sample."));	
}

//write filter descriptions
$filter_desc = array();
$filter_desc[] = array("low_conf_region", "Low confidence region for small variant calling based on gnomAD AC0/RF filters and IMGAG trio/twin data.");

//parse input
$c_written = 0;
$c_skipped_wgs = 0;
$multi_cols = array();
$hgnc_messages = array();
$in_header = true;
$handle = fopen2($in, "r");
$handle_out = fopen2($out, "w");
fwrite($handle_out, "##GENOME_BUILD=GRCh38\n");
$skip_ngsd = true; // true as long as no NGSD header is found
$skip_ngsd_som = true; // true as long as no NGSD somatic header is found
$skip_cosmic_cmc = true; //true as long as no COSMIC Cancer Mutation Census (CMC) header is found.
$skip_cancerhotspots = true; //true as long as no CANCERHOTSPOTS header is found.

//write date (of input file)
fwrite($handle_out, "##CREATION_DATE=".date("Y-m-d", filemtime($in))."\n");

while(!feof($handle))
{
	$line = nl_trim(fgets($handle));
	if ($line=="" || trim($line)=="") continue;
	
	//write header
	if ($line[0]=="#") 
	{
		if (starts_with($line, "##FILTER=<ID="))
		{
			$parts = explode(",Description=\"", substr(trim($line), 13, -2));
			fwrite($handle_out, "##FILTER=".$parts[0]."=".$parts[1]."\n");
		}
		
		if (starts_with($line, "##SAMPLE="))
		{
			$line = trim($line);
			fwrite($handle_out, $line."\n");
			list($name) = explode(",", substr($line, 13, -1));
			if ($genotype_mode=="single")
			{
				if ($column_desc[0][0]!="genotype")
				{
					trigger_error("Several sample header lines in 'single' mode!", E_USER_ERROR);
				}
				$column_desc[0][0] = $name;
			}
			else if ($genotype_mode=="multi")
			{
				$multi_cols[] = $name;
				array_splice($column_desc, count($multi_cols)-1, 0, array(array($name, "genotype of sample $name")));
			}
		}
		
		if (starts_with($line, "##ANALYSISTYPE="))
		{
			fwrite($handle_out, trim($line)."\n");
		}
		
		if (starts_with($line, "##PIPELINE="))
		{
			fwrite($handle_out, trim($line)."\n");
		}
		
		//get annotation indices in CSQ field from VEP
		if (starts_with($line, "##INFO=<ID=CSQ,"))
		{
			$cols = explode("|", substr($line, 0, -2));
			$i_consequence = index_of($cols, "Consequence", "CSQ");
			$i_feature = index_of($cols, "Feature", "CSQ");
			$i_featuretype = index_of($cols, "Feature_type", "CSQ");
			$i_biotype = index_of($cols, "BIOTYPE", "CSQ");
			$i_domains = index_of($cols, "DOMAINS", "CSQ");
			$i_sift = index_of($cols, "SIFT", "CSQ");
			$i_polyphen = index_of($cols, "PolyPhen", "CSQ");
			$i_existingvariation = index_of($cols, "Existing_variation", "CSQ");
			$i_maxes_ref = index_of($cols, "MaxEntScan_ref", "CSQ");
			$i_maxes_alt = index_of($cols, "MaxEntScan_alt", "CSQ");
			$i_pubmed = index_of($cols, "PUBMED", "CSQ"); 
		}

		//get annotation indices in CSQ field from VcfAnnotateConsequence
		if (starts_with($line, "##INFO=<ID=CSQ2,"))
		{
			$cols = explode("|", substr($line, 0, -2));
			$i_vac_consequence = index_of($cols, "Consequence", "CSQ2");
			$i_vac_impact = index_of($cols, "IMPACT", "CSQ2");
			$i_vac_symbol = index_of($cols, "SYMBOL", "CSQ2");
			$i_vac_hgnc_id = index_of($cols, "HGNC_ID", "CSQ2");
			$i_vac_feature = index_of($cols, "Feature", "CSQ2");
			$i_vac_exon = index_of($cols, "EXON", "CSQ2");
			$i_vac_intron = index_of($cols, "INTRON", "CSQ2");
			$i_vac_hgvsc = index_of($cols, "HGVSc", "CSQ2");
			$i_vac_hgvsp = index_of($cols, "HGVSp", "CSQ2");		
		}
		
		// detect NGSD header lines
		if (starts_with($line, "##INFO=<ID=NGSD_"))
		{
			$skip_ngsd = false;
			if (starts_with($line, "##INFO=<ID=NGSD_SOM_"))
			{
				$skip_ngsd_som = false;
			}
		}
		
		//COSMIC CMC (Cancer Mutation Census) header line
		if (starts_with($line, "##INFO=<ID=COSMIC_CMC,") )
		{
			$skip_cosmic_cmc = false;
		
			//trim to " (from file "... and split by "|"
			$cols = explode("|", substr($line, 0, strpos($line, " (from file")) );
			$cols[0] = "GENE_NAME";			
			$i_cosmic_cmc_gene_name = index_of($cols, "GENE_NAME", "COSMIC_CMC");
			$i_cosmic_cmc_mut_id = index_of($cols, "GENOMIC_MUTATION_ID", "COSMIC_CMC");
			$i_cosmic_cmc_disease = index_of($cols, "DISEASE", "COSMIC_CMC");
			$i_cosmic_cmc_dnds_disease = index_of($cols, "DNDS_DISEASE_QVAL", "COSMIC_CMC");
			$i_cosmic_cmc_mut_sign_tier = index_of($cols, "MUTATION_SIGNIFICANCE_TIER", "COSMIC_CMC");
		}
		
		//Cancerhotspots.org header line
		if( starts_with($line, "##INFO=<ID=CANCERHOTSPOTS,") )
		{
			$skip_cancerhotspots = false;
			$cols = explode("|", substr($line, 0,-2) );
			$cols[0] = "GENE_SYMBOL";			
			$i_cancerhotspots_gene_symbol = index_of($cols, "GENE_SYMBOL", "CANCERHOTSPOTS");
			$i_cancerhotspots_transcript_id = index_of($cols, "ENSEMBL_TRANSCRIPT", "CANCERHOTSPOTS");
			$i_cancerhotspots_aa_pos = index_of($cols, "AA_POS", "CANCERHOTSPOTS");
			$i_cancerhotspots_aa_ref = index_of($cols, "AA_REF", "CANCERHOTSPOTS");
			$i_cancerhotspots_aa_alt = index_of($cols, "AA_ALT", "CANCERHOTSPOTS");
			$i_cancerhotspots_total_count = index_of($cols, "TOTAL_COUNT", "CANCERHOTSPOTS");
			$i_cancerhotspots_alt_count = index_of($cols, "ALT_COUNT", "CANCERHOTSPOTS");
		}
		
		continue;
	}
	//after last header line, write our header
	else if ($in_header) 
	{
		write_header_line($handle_out, $column_desc, $filter_desc);
		$in_header = false;
	}
	
	//write content lines
	$cols = explode("\t", $line);
	if (count($cols)<10) trigger_error("VCF file line contains less than 10 columns: '$line'", E_USER_ERROR);
	list($chr, $pos, $id, $ref, $alt, $qual, $filter, $info, $format, $sample) = $cols;
	$tag = "{$chr}:{$pos} {$ref}>{$alt}";
	if ($filter=="" || $filter=="." || $filter=="PASS")
	{
		$filter = array();
	}
	else
	{
		$filter = explode(";", $filter);
	}

	//parse variant data from VCF
	if(chr_check($chr, 22, false) === FALSE) continue; //skip bad chromosomes
	$start = $pos;
	$end = $pos;
	$ref = strtoupper($ref);
	$alt = strtoupper($alt);
	if(strlen($ref)>1 || strlen($alt)>1) //correct indels
	{
		list($start, $end, $ref, $alt) = correct_indel($start, $ref, $alt);
	}
	
	//skip too long variants (unique constraint in NGSD fails otherwise)
	if (strlen($ref)>500 || strlen($alt)>500) continue;
	
	//parse info from VCF
	$info = explode(";", $info);
	$tmp = array();
	foreach($info as $entry)
	{
		if (!contains($entry, "="))
		{
			$tmp[$entry] = "";
		}
		else
		{
			list($key, $value) = explode("=", $entry, 2);
			$tmp[$key] = $value;
		}
	}
	$info = $tmp;

	$sample = array_combine(explode(":", $format), explode(":", $sample));
	
	//convert genotype information to TSV format
	if ($genotype_mode=="multi")
	{
		if (!isset($sample["MULTI"])) 
		{
			trigger_error("VCF sample column does not contain MULTI value!", E_USER_ERROR);
		}
		
		//extract GT/DP/AO info
		$tmp = array();
		$tmp2 = array();
		$tmp3 = array();
		$parts = explode(",", $sample["MULTI"]);
		foreach($parts as $part)
		{
			list($name, $gt, $dp, $ao) = explode("=", strtr($part, "|", "=")."=");
			$tmp[$name] = $gt;
			$tmp2[$name] = $dp;
			$tmp3[$name] = $ao;
		}
		
		//recombine GT/DP/AO in the correct order
		$genotypes = array();
		$depths = array();
		$aos = array();
		foreach($multi_cols as $col)
		{
			$gt = $tmp[$col];
			$dp = $tmp2[$col];
			$ao = $tmp3[$col];
			if ($dp<3) $gt = "n/a";
			$genotypes[] = $gt;
			$depths[] = $dp;
			$aos[] = $ao;
		}
		$genotype = "\t".implode("\t", $genotypes);
		$sample["DP"] = implode(",", $depths);
		$sample["AO"] = implode(",", $aos);
	}
	else if ($genotype_mode=="single")
	{
		if (!isset($sample["GT"])) 
		{
			trigger_error("VCF sample column does not contain GT value!", E_USER_ERROR);
		}
		$genotype = vcfgeno2human($sample["GT"]);
		
		//skip wildtype
		if ($genotype=="wt") continue;
		
		$genotype = "\t".$genotype;
	}
	else if ($genotype_mode=="skip")
	{
		$genotype = "";
	}
	else
	{
		trigger_error("Invalid mode '{$genotype_mode}'!", E_USER_ERROR);
	}

	//quality
	$quality = array();
	$qual = intval($qual);
	$quality[] = "QUAL=".$qual;
	if (isset($sample["DP"]))
	{
		$quality[] = "DP=".$sample["DP"];
	}
	
	//QD
	if (isset($sample["DP"])) //freebayes
	{
		if (is_numeric($sample["DP"]))
		{
			$quality[] = "QD=".number_format($qual/$sample["DP"], 2);
		}
	}
	if (isset($sample["QD"])) //Dragen
	{
		$quality[] = "QD=".$sample["QD"];
	}
	
	//AF
	if (isset($sample["AO"]) && isset($sample["DP"])) //freebayes
	{
		//comma-separated values in case of multi-sample data
		$afs = array();
		$aos = explode(",", $sample["AO"]);
		$dps = explode(",", $sample["DP"]);
		for($i=0; $i<count($dps); ++$i)
		{
			if (is_numeric($aos[$i]) && is_numeric($dps[$i]) && $dps[$i]>0)
			{
				$afs[] = number_format($aos[$i]/$dps[$i], 2);
			}
			else if ($genotype_mode=="multi")
			{
				$afs[] = "";
			}
		}
		if (count($afs)>0)
		{
			$quality[] = "AF=".implode(",", $afs);
		}
	}
	if (isset($sample["AF"])) //Dragen
	{
		$quality[] = "AF=".$sample["AF"];
	}
	
	//MQM
	if (isset($info["MQM"])) //freebayes
	{
		$quality[] = "MQM=".intval($info["MQM"]);
	}
	if (isset($info["MQ"])) //Dragen
	{
		$quality[] = "MQM=".intval($info["MQ"]);
	}
	
	//other quality fields specific to freebayes
	if (isset($info["SAP"])) 
	{
		$quality[] = "SAP=".intval($info["SAP"]);
	}
	if (isset($info["ABP"])) 
	{
		$quality[] = "ABP=".intval($info["ABP"]);
	}
	if (isset($info["SAR"]))
	{
		$quality[] = "SAR=".intval($info["SAR"]);
	}
	if (isset($info["SAF"]))
	{
		$quality[] = "SAF=".intval($info["SAF"]);
	}
	
	$phylop = array();
	if (isset($info["PHYLOP"])) 
	{
		$phylop[] = $info["PHYLOP"];
	}

	$revel = array();
	if (isset($info["REVEL"])) 
	{
		$revel[] = $info["REVEL"];
	}
	
	//variant details
	$sift = array();
	$polyphen = array();
	$dbsnp = array();
	$cosmic = array();
	$genes = array();
	$variant_details = array();
	$coding_and_splicing_details = array();
	$af_gnomad = array();
	$af_gnomad_genome = array();
	$af_gnomad_afr = array();
	$af_gnomad_amr = array();
	$af_gnomad_eas = array();
	$af_gnomad_nfe = array();
	$af_gnomad_sas = array();
	$hom_gnomad = array();
	$hemi_gnomad = array();
	$wt_gnomad = array();
	$het_gnomad = array();
	$clinvar = array();
	$hgmd = array();
	$maxentscan = array();
	$regulatory = array();
	$pubmed = array();
	
	//variant details (up/down-stream)
	$variant_details_updown = array();
	$genes_updown = array();
	$sift_updown = array();
	$polyphen_updown = array();
	$coding_and_splicing_details_updown = array(); 

	if (isset($info["CSQ"]) && isset($info["CSQ2"]))
	{
		//VEP - used for regulatory features, dbSNP, COSMIC, MaxEntScan, PubMed, Sift, PolyPhen, Domains
		$vep = []; //transcript name without version > [domain, sift, polyphen]
		foreach(explode(",", $info["CSQ"]) as $entry)
		{			
			$parts = explode("|", $entry);
			
			//######################### general information (not transcript-specific) #########################
			
			//dbSNP, COSMIC
			$ids = explode("&", $parts[$i_existingvariation]);
			foreach($ids as $id)
			{
				if (starts_with($id, "rs"))
				{
					$dbsnp[] = $id;
				}
				if (starts_with($id, "COSM") || starts_with($id, "COSN") || starts_with($id, "COSV"))
				{
					$cosmic[] = $id;
				}
			}
			
			//MaxEntScan
			if ($parts[$i_maxes_ref]!="")
			{
				$result = number_format($parts[$i_maxes_ref], 2).">".number_format($parts[$i_maxes_alt], 2);
				$maxentscan[] = $result;
			}

			//PubMed ids
			if ($i_pubmed!==FALSE)
			{
				$pubmed = array_merge($pubmed, explode("&", $parts[$i_pubmed]));
			}
			
			//######################### transcript-specific information #########################
			$feature_type = trim($parts[$i_featuretype]);
			if ($feature_type=="Transcript")
			{
				$transcript_id = trim($parts[$i_feature]);	

				//domain
				$domain = "";
				$domains = explode("&", $parts[$i_domains]);
				foreach($domains as $entry)
				{
					if(starts_with($entry, "Pfam:"))
					{
						$domain = explode(":", $entry, 2)[1];
					}
				}
				
				// extend domain ID by description
				if ($domain != "")
				{
					$domain_description = "";
					
					// update Pfam ID 
					if (array_key_exists($domain, $pfam_replacements))
					{
						if ($pfam_replacements[$domain] == "")
						{
							$domain_description = "removed";
						}
						else
						{
							$domain_description = "(new id of $domain) ";
							$domain = $pfam_replacements[$domain];
						}
					}
					// append description
					if (array_key_exists($domain, $pfam_description))
					{
						$domain_description = $domain_description.$pfam_description[$domain];
					}

					// throw error if Pfam id is neither found in replacement data nor in description data
					if ($domain_description == "")
					{
						trigger_error("No description found for '$domain'!", E_USER_WARNING);
					}

					// combine decription and id
					$domain = "$domain [$domain_description]";
				}
				
				$transcript_id_no_ver = explode('.', $transcript_id)[0];
				
				$vep[$transcript_id_no_ver] = [ $domain, $parts[$i_sift], $parts[$i_polyphen] ];			
			}
			else if ($feature_type=="RegulatoryFeature")
			{
				$regulatory[] = $parts[$i_consequence].":".$parts[$i_biotype];
			}
			else if ($feature_type=="MotifFeature")
			{
				$regulatory[] = $parts[$i_consequence];
			}
			else if ($feature_type!="") //feature type is empty for intergenic variants
			{				
				trigger_error("Unknown VEP feature type '{$feature_type}' for variant {$chr}:{$pos} {$ref}>{$alt}!", E_USER_ERROR);
			}
		}
		
		//VcfAnnotateConsequence
		foreach(explode(",", $info["CSQ2"]) as $entry)
		{			
			$parts = explode("|", $entry);
			
			$transcript_id = trim($parts[$i_vac_feature]);
			
			$consequence = $parts[$i_vac_consequence];
			$consequence = strtr($consequence, ["&NMD_transcript"=>"", "splice_donor_variant&intron_variant"=>"splice_donor_variant", "splice_acceptor_variant&intron_variant"=>"splice_acceptor_variant"]);
			
			//extract variant type
			$variant_type = strtr($consequence, array("_variant"=>"", "_prime_"=>"'"));
			$is_updown = $variant_type=="upstream_gene" || $variant_type=="downstream_gene";
			if (!$is_updown)
			{
				$variant_details[] = $variant_type;
			}
			else
			{
				$variant_details_updown[] = $variant_type;
			}
			
			//determine gene name (update if neccessary)
			$gene = trim($parts[$i_vac_symbol]);
			if ($gene!="")
			{
				$hgnc_id = $parts[$i_vac_hgnc_id];
				$hgnc_id = trim(strtr($hgnc_id, array("HGNC:"=>"")));
				if (isset($hgnc[$hgnc_id]))
				{
					$hgnc_gene = $hgnc[$hgnc_id];
					if ($gene!=$hgnc_gene)
					{
						$gene = $hgnc_gene;
					}
				}
				else if ($hgnc_id!="")
				{
					@$hgnc_messages["ID '$hgnc_id' not valid or withdrawn for gene '$gene'"] += 1;
				}
				
				if (!$is_updown)
				{
					 $genes[] = $gene;
				}
				else
				{
					$genes_updown[] = $gene;
				}
			}
			
			//exon
			$exon = trim($parts[$i_vac_exon]);
			if ($exon!="") $exon = "exon".$exon;
			$intron = trim($parts[$i_vac_intron]);
			if ($intron!="") $intron = "intron".$intron;
			
			//hgvs
			$hgvs_c = trim($parts[$i_vac_hgvsc]);
			$hgvs_p = trim($parts[$i_vac_hgvsp]);
			$hgvs_p = str_replace("%3D", "=", $hgvs_p);
			
			//domain, SIFT and Polyphen from VEP
			$domain = "";
			$transcript_id_no_ver = explode('.', $transcript_id)[0];
			if (isset($vep[$transcript_id_no_ver]))
			{
				list($domain, $sift_raw, $polyphen_raw) = $vep[$transcript_id_no_ver];
				
				//SIFT
				list($sift_type, $sift_score) = explode("(", strtr($sift_raw, ")", "(")."(");
				if ($sift_type!="")
				{
					$sift_type = translate("Sift", $sift_type, array("deleterious"=>"D", "tolerated"=>"T", "tolerated_low_confidence"=>"T", "deleterious_low_confidence"=>"D"));
					$sift_score = number_format($sift_score, 2);
					$sift_entry = $sift_type."($sift_score)";
				}
				else
				{
					$sift_entry = " ";
				}
				if (!$is_updown)
				{
					$sift[] = $sift_entry;
				}
				else
				{
					$sift_updown[] = $sift_entry;
				}
				
				//Polyphen
				list($pp_type, $pp_score) = explode("(", strtr($polyphen_raw, ")", "(")."(");
				if ($pp_type!="")
				{
					$pp_type = translate("PolyPhen", $pp_type, array("unknown"=>" ",  "probably_damaging"=>"D", "possibly_damaging"=>"P", "benign"=>"B"));
					$pp_score = number_format($pp_score, 2);
					if (!is_numeric($pp_score))
					{
						print "ERROR: ".$polyphen_raw." ".$pp_score."\n";
					}
					$polyphen_entry = $pp_type."($pp_score)";
				}
				else
				{
					$polyphen_entry = " ";
				}
				if (!$is_updown)
				{
					$polyphen[] = $polyphen_entry;
				}
				else
				{
					$polyphen_updown[] = $polyphen_entry;
				}
			}
			//add transcript information
			$transcript_entry = "{$gene}:{$transcript_id}:".$consequence.":".$parts[$i_vac_impact].":{$exon}{$intron}:{$hgvs_c}:{$hgvs_p}:{$domain}";
			if (!$is_updown)
			{
				$coding_and_splicing_details[] = $transcript_entry;
			}
			else
			{
				$coding_and_splicing_details_updown[] = $transcript_entry;
			}
		}	
	}

	//gnomAD genome
	if (isset($info["gnomADg_AF"]))
	{
		$gnomad_values = explode("&", $info["gnomADg_AF"]); // special handling of the rare case that several gnomAD AF values exist
		foreach($gnomad_values as $value)
		{
			if ($value=="" || $value==".") continue;
			$af_gnomad_genome[] = $value;
		}
	}
	
	//gnomAD mito
	if (isset($info["gnomADm_AF_hom"]))
	{
		$gnomad_values = explode("&", $info["gnomADm_AF_hom"]); // special handling of the rare case that several gnomAD AF values exist
		foreach($gnomad_values as $value)
		{
			if ($value=="" || $value==".") continue;
			$af_gnomad_genome[] = $value;
		}
	}
	
	//gnomAD hom/hemi
	if (isset($info["gnomADg_Hom"])) $hom_gnomad[] = trim($info["gnomADg_Hom"]);
	if (isset($info["gnomADg_Hemi"])) $hemi_gnomad[] = trim($info["gnomADg_Hemi"]);
	if (isset($info["gnomADg_Het"])) $het_gnomad[] = trim($info["gnomADg_Het"]);
	if (isset($info["gnomADg_Wt"])) $wt_gnomad[] = trim($info["gnomADg_Wt"]);

	//genomAD sub-populations
	if (isset($info["gnomADg_AFR_AF"])) $af_gnomad_afr[] = trim($info["gnomADg_AFR_AF"]);
	if (isset($info["gnomADg_AMR_AF"])) $af_gnomad_amr[] = trim($info["gnomADg_AMR_AF"]);
	if (isset($info["gnomADg_EAS_AF"])) $af_gnomad_eas[] = trim($info["gnomADg_EAS_AF"]);
	if (isset($info["gnomADg_NFE_AF"])) $af_gnomad_nfe[] = trim($info["gnomADg_NFE_AF"]);
	if (isset($info["gnomADg_SAS_AF"])) $af_gnomad_sas[] = trim($info["gnomADg_SAS_AF"]);

	//ClinVar
	if (isset($info["CLINVAR_ID"]) && isset($info["CLINVAR_DETAILS"]))
	{
		$clin_accs = explode("&", trim($info["CLINVAR_ID"]));
		$clin_details = explode("&", trim($info["CLINVAR_DETAILS"]));
		for ($i=0; $i<count($clin_accs); ++$i)
		{
			if ($clin_accs[$i]!="")
			{
				$clinvar[] = $clin_accs[$i]." [".strtr(vcf_decode_url_string($clin_details[$i]), array("_"=>" "))."];";
			}
		}
	}

	//HGMD
	if (isset($info["HGMD_ID"]))
	{
		$hgmd_id = trim($info["HGMD_ID"]);
		$hgmd_class = trim($info["HGMD_CLASS"]);
		$hgmd_mut = trim($info["HGMD_MUT"]);
		$hgmd_gene = trim($info["HGMD_GENE"]);
		$hgmd_phen = trim($info["HGMD_PHEN"]);
		
		$hgmd[] = trim($hgmd_id." [CLASS=".$hgmd_class." MUT=".$hgmd_mut." PHEN=".strtr($hgmd_phen, "_", " ")." GENE=".$hgmd_gene."];");
	}

	//AFs
	$dbsnp = implode(",", collapse($tag, "dbSNP", $dbsnp, "unique"));	
	$gnomad = collapse($tag, "gnomAD genome", $af_gnomad_genome, "max", 4);
	$gnomad_hom_hemi = collapse($tag, "gnomAD Hom", $hom_gnomad, "one").",".collapse($tag, "gnomAD Hemi", $hemi_gnomad, "one");
	if ($gnomad_hom_hemi==",") $gnomad_hom_hemi = "";
	$gnomad_sub = collapse($tag, "gnomAD AFR", $af_gnomad_afr, "one", 4).",".collapse($tag, "gnomAD AMR", $af_gnomad_amr, "one", 4).",".collapse($tag, "gnomAD EAS", $af_gnomad_eas, "one", 4).",".collapse($tag, "gnomAD NFE", $af_gnomad_nfe, "one", 4).",".collapse($tag, "gnomAD SAS", $af_gnomad_sas, "one", 4);
	if (str_replace(",", "", $gnomad_sub)=="") $gnomad_sub = "";
	$gnomad_het = collapse($tag, "gnomAD Het", $het_gnomad, "one");
	$gnomad_wt = collapse($tag, "gnomAD Wt", $wt_gnomad, "one");

	//PubMed
	$pubmed = implode(",", collapse($tag, "PubMed", $pubmed, "unique"));	


	if (!$skip_ngsd)
	{
		// extract NGSD somatic counts
		if (!$skip_ngsd_som)
		{
			if (isset($info["NGSD_SOM_C"]))
			{
				$ngsd_som_counts = intval(trim($info["NGSD_SOM_C"]));
			}
			else
			{
				$ngsd_som_counts = "0";
			}

			if (isset($info["NGSD_SOM_P"]))
			{
				$ngsd_som_projects = vcf_decode_url_string(trim($info["NGSD_SOM_P"]));
			}
			else
			{
				$ngsd_som_projects = "";
			}
			
			if (isset($info["NGSD_SOM_VICC"]))
			{
				$ngsd_som_vicc = trim($info["NGSD_SOM_VICC"]);
			}
			else
			{
				$ngsd_som_vicc = "";
			}
			
			if (isset($info["NGSD_SOM_VICC_COMMENT"]))
			{
				$ngsd_som_vicc_comment = vcf_decode_url_string(trim($info["NGSD_SOM_VICC_COMMENT"]) );
			}
			else
			{
				$ngsd_som_vicc_comment = "";
			}
		}

		//NGSD
		if (isset($info["NGSD_HAF"]) || $gnomad >= 0.05)
		{
			$ngsd_hom = "n/a (AF>5%)";
			$ngsd_het = "n/a (AF>5%)";
			$ngsd_group = "n/a (AF>5%)";
		}
		elseif(isset($info["NGSD_COUNTS"]))
		{
			$ngsd_counts = explode(",", trim($info["NGSD_COUNTS"]));
			$ngsd_hom = $ngsd_counts[0];
			$ngsd_het = $ngsd_counts[1];
			if (isset($info["NGSD_GROUP"]))
			{
				$ngsd_group_raw = explode(",", trim($info["NGSD_GROUP"]));
				$ngsd_group = intval($ngsd_group_raw[0])." / ".intval($ngsd_group_raw[1]);
			}
			else
			{
				$ngsd_group = "0 / 0";
			}
		}
		else
		{
			$ngsd_hom = "0";
			$ngsd_het = "0";
			$ngsd_group = "0 / 0";
		}

		if (isset($info["NGSD_CLAS"]))
		{
			$ngsd_clas = trim($info["NGSD_CLAS"]);
		}
		else
		{
			$ngsd_clas = "";
		}

		if (isset($info["NGSD_CLAS_COM"]))
		{
			$ngsd_clas_com = vcf_decode_url_string(trim($info["NGSD_CLAS_COM"]));
		}
		else
		{
			$ngsd_clas_com = "";
		}

		if (isset($info["NGSD_COM"]))
		{
			$ngsd_com = vcf_decode_url_string(trim($info["NGSD_COM"]));
		}
		else
		{
			$ngsd_com = "";
		}

		if (isset($info["NGSD_VAL"]))
		{
			$ngsd_val = trim($info["NGSD_VAL"]);
		}
		else
		{
			$ngsd_val = "";
		}

		if (isset($info["NGSD_GENE_INFO"]))
		{
			$ngsd_gene_info = trim(str_replace("&", ", ", vcf_decode_url_string($info["NGSD_GENE_INFO"])));
		}
		else
		{
			$ngsd_gene_info = "";
		}
	}
	
	//SpliceAI
	$spliceai = "";
	if (isset($info["SpliceAI"]))
	{
		$splice_number = null;
		$spliceai_info = trim($info["SpliceAI"]);
		$spliceai_values = array();

		$entries = explode(",", $spliceai_info);
		foreach($entries as $entry)
		{
			$delta_scores = explode("|", $entry);
			if(count($delta_scores) == 10)
			{
				$tmp_score = max(floatval($delta_scores[2]), floatval($delta_scores[3]), floatval($delta_scores[4]), floatval($delta_scores[5]));
				if(is_null($splice_number)) $splice_number = $tmp_score;
				$splice_number = max($splice_number, $tmp_score);
			}
			else
			{
				trigger_error("Wrong SpliceAI annotation in line: ${line} in SpliceAI annotation: ${spliceai_info}! Delimiter for several genes must be ','.", E_USER_WARNING);
			}
		}

		if(!is_null($splice_number))
		{
			$spliceai = $splice_number;
		}

	}

	// CADD
	$cadd_scores = array();
	if (isset($info["CADD_SNV"]))
	{
		$cadd_scores = array_map(function($score){return number_format($score, 2, ".", "");}, explode("&", $info["CADD_SNV"]));
	}
	if (isset($info["CADD_INDEL"]))
	{
		$cadd_scores = array_map(function($score){return number_format($score, 2, ".", "");}, explode("&", $info["CADD_INDEL"]));
	}
	if (count(array_unique($cadd_scores)) == 0)
	{
		//No CADD score available
		$cadd = "";
	}
	else if (count(array_unique($cadd_scores)) > 1)
	{
		//trigger_error("Multiple values for CADD score for variant $chr:$pos! Choosing max value.", E_USER_WARNING);
		$cadd = max($cadd_scores);
	}
	else
	{
		$cadd = $cadd_scores[0];
	}
	
	// COSMIC CMC
	if ( !$skip_cosmic_cmc && isset($info["COSMIC_CMC"]) )
	{
		$anns = explode("&", $info["COSMIC_CMC"]);
		
		$cmc_gene = array();
		$cmc_mut_id = array();
		$cmc_disease = array();
		$cmc_dnds_disease = array();
		$cmc_mut_sign_tier = array();
		

		foreach($anns as $entry)
		{
			$parts = explode("|", $entry);
			
			$cmc_gene[] = vcf_decode_url_string( $parts[$i_cosmic_cmc_gene_name] );
			$cmc_mut_id[] = vcf_decode_url_string( $parts[$i_cosmic_cmc_mut_id] ) ;
			$cmc_disease[] = vcf_decode_url_string( $parts[$i_cosmic_cmc_disease] );
			$cmc_dnds_disease[] = vcf_decode_url_string( $parts[$i_cosmic_cmc_dnds_disease] );
			$cmc_mut_sign_tier[] = vcf_decode_url_string( $parts[$i_cosmic_cmc_mut_sign_tier] );
			
		}
	}
	
	// CANCERHOTSPOTS
	if( !$skip_cancerhotspots && isset($info["CANCERHOTSPOTS"]) )
	{
		$anns = explode(",", $info["CANCERHOTSPOTS"] );
		
		$cancerhotspots_protein_change = array();
		$cancerhotspots_total_count = array();
		$cancerhotspots_alt_count = array();
		
		foreach($anns as $entry)
		{
			$parts = explode("|", $entry);
			$cancerhotspots_protein_change[] = ($parts[$i_cancerhotspots_transcript_id]) .":p." . aa1_to_aa3($parts[$i_cancerhotspots_aa_ref]) .$parts[$i_cancerhotspots_aa_pos] .  aa1_to_aa3($parts[$i_cancerhotspots_aa_alt]);
			$cancerhotspots_total_count[] =  $parts[$i_cancerhotspots_total_count];
			$cancerhotspots_alt_count[] = $parts[$i_cancerhotspots_alt_count];
		}
	}

	//add up/down-stream variants if requested (or no other transcripts exist)
	if ($updown || count($coding_and_splicing_details)==0)
	{
		$variant_details = array_merge($variant_details, $variant_details_updown);
		$genes = array_merge($genes, $genes_updown);
		$sift = array_merge($sift, $sift_updown);
		$polyphen = array_merge($polyphen, $polyphen_updown);
		$coding_and_splicing_details = array_merge($coding_and_splicing_details, $coding_and_splicing_details_updown);
	}
	
	$variant_details = implode(",", array_unique($variant_details));
	$coding_and_splicing_details =  implode(",", $coding_and_splicing_details);
	
	//regulatory
	$regulatory = implode(",", collapse($tag, "Regulatory", $regulatory, "unique"));

	//RepeatMasker
	$repeatmasker = "";
	if (isset($info["REPEATMASKER"]))
	{
		$repeatmasker = trim(str_replace("&", ", ", vcf_decode_url_string($info["REPEATMASKER"])));
	}
	
	//effect predicions
	$phylop = collapse($tag, "phyloP", $phylop, "one", 4);
	$sift = implode(",", $sift);
	if (trim(strtr($sift, ",", " "))=="") $sift = "";
	$polyphen = implode(",", $polyphen);
	if (trim(strtr($polyphen, ",", " "))=="") $polyphen = "";
	
	$revel = empty($revel) ? "" : collapse($tag, "REVEL", $revel, "max", 2);
	
	//OMIM
	$omim = "";
	if (isset($info["OMIM"]))
	{
		$omim = trim(vcf_decode_url_string($info["OMIM"]));
	}

	//ClinVar
	$clinvar = implode(" ", collapse($tag, "ClinVar", $clinvar, "unique"));
	
	//HGMD
	$hgmd = collapse($tag, "HGMD", $hgmd, "one");

	//MaxEntScan
	$maxentscan = implode(",", collapse($tag, "MaxEntScan", $maxentscan, "unique"));
	
	//COSMIC
	$cosmic = implode(",", collapse($tag, "COSMIC", $cosmic, "unique"));
	
	//skip common MODIFIER variants in WGS mode
	if ($wgs && skip_in_wgs_mode($chr, $coding_and_splicing_details, $gnomad, $clinvar, $hgmd, $ngsd_clas))
	{
		++$c_skipped_wgs;
		continue;
	}
	
	//write data
	++$c_written;
	$genes = array_unique($genes);
	fwrite($handle_out, "$chr\t$start\t$end\t$ref\t{$alt}{$genotype}\t".implode(";", $filter)."\t".implode(";", $quality)."\t".implode(",", $genes)."\t$variant_details\t$coding_and_splicing_details\t$regulatory\t$omim\t$clinvar\t$hgmd\t$repeatmasker\t$dbsnp\t$gnomad\t$gnomad_sub\t$gnomad_hom_hemi\t$gnomad_het\t$gnomad_wt\t$phylop\t$sift\t$polyphen\t$cadd\t$revel\t$maxentscan\t$cosmic\t$spliceai\t$pubmed");
	if (!$skip_ngsd_som)
	{
		fwrite($handle_out, "\t$ngsd_som_counts\t$ngsd_som_projects\t$ngsd_som_vicc\t$ngsd_som_vicc_comment");
	}
	if (!$skip_ngsd)
	{
		fwrite($handle_out, "\t$ngsd_hom\t$ngsd_het\t$ngsd_group\t$ngsd_clas\t$ngsd_clas_com\t$ngsd_val\t$ngsd_com\t$ngsd_gene_info");
	}
	
	if ( !$skip_cosmic_cmc && isset($info["COSMIC_CMC"]) )
	{
		fwrite($handle_out, "\t". implode(",",$cmc_gene) ."\t". implode(",",$cmc_mut_id) ."\t". implode(",",$cmc_disease) ."\t" . implode(",",$cmc_dnds_disease) ."\t".implode(",",$cmc_mut_sign_tier));
	}
	elseif( !$skip_cosmic_cmc)
	{
		fwrite( $handle_out, str_repeat("\t", count($colum_desc_cosmic_cmc)) );
	}
	
	if( !$skip_cancerhotspots &&isset($info["CANCERHOTSPOTS"]) )
	{
		fwrite( $handle_out, "\t" . implode(",",  $cancerhotspots_protein_change) . "\t" . implode(",", $cancerhotspots_total_count) ."\t" . implode(",", $cancerhotspots_alt_count) ); 
	}
	elseif( !$skip_cancerhotspots )
	{
		fwrite( $handle_out, str_repeat("\t", count($column_desc_cancerhotspots) ) );
	}

	fwrite($handle_out, "\n");
}

//print HGNC messages
foreach($hgnc_messages as $message => $c)
{
	$parser->log("HGNC: {$message} ({$c}x)");
}

//if no variants are present, we need to write the header line after the loop
if ($in_header) 
{
	write_header_line($handle_out, $column_desc, $filter_desc);
}

fclose($handle);
fclose($handle_out);

//print debug output
print "Variants written: {$c_written}\n";
if ($wgs)
{
	print "Variants skipped because WGS mode is enabled: {$c_skipped_wgs}\n";
}


?>
