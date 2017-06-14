<?php 
/** 
	@page vc_manta
	@TODO tumor only -> tumorSV.vcf.gz will be generated by Manta
	@TODO germline -> dipoidSV.vcf.gz
	@TODO consider "High sensitivity calling" for enriched settings (s. https://github.com/Illumina/manta/blob/master/src/markdown/mantaUserGuide.md)
*/

require_once(dirname($_SERVER['SCRIPT_FILENAME'])."/../Common/all.php");

error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);

// add parameter for command line ${input1.metadata.bam_index}
// parse command line arguments
$parser = new ToolBase("vc_manta", "Call somatic strucural variants with manta. Creates an VCF file.");
$parser->addInfileArray("bam", "Normal BAM file. Only one bam file allowed for somatic mode.", false);
$parser->addString("out", "Output file (gzipped and tabix indexed).", false);
//optional
$parser->addInfile("t_bam", "Tumor BAM file.", true);
$parser->addFlag("exome", "If set settings for exome are used.", true);
$parser->addString("build", "The genome build to use.", true, "GRCh37");
extract($parser->parse($argv));

//run manta
$manta_folder = $parser->tempFolder()."/mantaAnalysis";
$genome = get_path("local_data")."/$build.fa";
$pars = "";
$pars .= "--referenceFasta $genome ";
$pars .= "--runDir $manta_folder --config ".get_path("manta")."/configManta.py.ini ";
$pars .= "--normalBam ".implode(" --normalBam ", $bam)." ";
if(isset($t_bam))
{
	if(count($bam)>1)	trigger_error("Only one bam file allowed in somatic mode!", E_USER_ERROR);
	$pars .= "--tumorBam $t_bam ";
}
if($exome)	$pars .= "--exome ";

$parser->exec("python ".get_path('manta')."/configManta.py", $pars,true);
$parser->exec("python $manta_folder/runWorkflow.py", " -m local -j4 -g4", false);

//merge vcf files
$vcf_combined = $parser->tempFile("_combined.vcf");
$results = $manta_folder."/results/variants/diploidSV.vcf.gz";
if(isset($t_bam))	$results = $manta_folder."/results/variants/somaticSV.vcf.gz";
$parser->exec("zcat","$results > $vcf_combined",true);

//zip and index output file
$parser->exec("bgzip", "-c $vcf_combined > $out", false);	//no output logging, because Toolbase::extractVersion() does not return
$parser->exec("tabix", "-p vcf $out", false);	//no output logging, because Toolbase::extractVersion() does not return
