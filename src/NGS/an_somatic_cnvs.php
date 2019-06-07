<?php
require_once(dirname($_SERVER['SCRIPT_FILENAME'])."/../Common/all.php");

$parser = new ToolBase("an_somatic_cnvs", "Annotates additional somatic data to ClinCNV file (CGI / NCG6.0).");
$parser->addInfile("cnv_in", "Input .gsvar-file with SNV data.", false);
$parser->addInfile("cnv_in_cgi", "Input CGI data with SNV annotations",true);
$parser->addFlag("include_ncg", "Annotate column with info from NCG6.0 whether a gene is TSG or oncogene");
$parser->addOutfile("out", "Output file name", false);
extract($parser->parse($argv));

if(isset($cnv_in_cgi))
{
	$cnv_input = Matrix::fromTSV($cnv_in);
	$cgi_input = Matrix::fromTSV($cnv_in_cgi);
	
	//data from CGI cnv file
	$cgi_genes = $cgi_input->getCol($cgi_input->getColumnIndex("gene"));
	$cgi_driver_statements = $cgi_input->getCol($cgi_input->getColumnIndex("driver_statement"));
	$cgi_gene_roles = $cgi_input->getCol($cgi_input->getColumnIndex("gene_role"));
	$cgi_alteration_types = $cgi_input->getCol($cgi_input->getColumnIndex("cna"));
	//data from cnv file
	$cnv_genes = $cnv_input->getCol($cnv_input->getColumnIndex("genes"));
	//approved cnv genes
	$approved_cnv_genes = array();
	foreach($cnv_genes as $gene)
	{
		$temp_genes = approve_gene_names(explode(',',$gene));
		$approved_cnv_genes[] = implode(',',$temp_genes);
	}

	$i_cn_cnvhunter = $cnv_input->getColumnIndex("region_copy_numbers",false,false); //CNVHunter
	$i_cn_clincnv = $cnv_input->getColumnIndex("CN_change",false,false); //ClinCnv

	if(($i_cn_cnvhunter == false && $i_cn_clincnv == false) || ($i_cn_cnvhunter !== false && $i_cn_clincnv !== false))
	{
		trigger_error("Unknown format of CNV file {$cnv_in}. Aborting...",E_USER_ERROR);
	}

	$is_cnvhunter = false;
	if($i_cn_clincnv == false) $is_cnvhunter = true;

	$cn = $cnv_input->getCol($i_cn_clincnv); //column with copy numbers
	if($is_cnvhunter) $cn = $cnv_input->getCol($i_cn_cnvhunter); //CNVhunter file

	//new columns for CNV input
	$new_driver_statement = array();
	$new_gene_role = array();
	$new_genes = array();
	for($i=0;$i<$cnv_input->rows();$i++)
	{
		$new_driver_statement[] = "";
		$new_gene_role[] = "";
		$new_genes[] = "";
	}

	for($i=0;$i<count($cgi_genes);$i++)
	{
		$cgi_gene = $cgi_genes[$i];
		$cgi_driver_statement = $cgi_driver_statements[$i];
		//In rare cases, CGI statement contains ",": must be removed because it is used as separator
		$cgi_driver_statement = str_replace(",",";",$cgi_driver_statement);
		
		$cgi_gene_role = $cgi_gene_roles[$i];
		$cgi_alteration_type = $cgi_alteration_types[$i];
		
		for($j=0;$j<count($cnv_genes);$j++)
		{
			$genes = explode(',',$cnv_genes[$j]);
			
			//calc Copy number alteration type (amp or del) (!per region!) in input cnv file		

			$cnv_alteration_type = "";
			
			if($is_cnvhunter) //CNVHunter
			{
				$copy_numbers_region = explode(',',$cn[$j]);
				$median_copy_number = median($copy_numbers_region);
				if($median_copy_number>2.)
				{
					$cnv_alteration_type = "AMP";
				} else {
					$cnv_alteration_type = "DEL";
				}
			}
			else //ClinCNV
			{
				if($cn[$j] > 2.) $cnv_alteration_type = "AMP";
				elseif($cn[$j] < 2.) $cnv_alteration_type = "DEL";
				else $cnv_alteration_type = "NA";
			}
			
			foreach($genes as $cnv_gene)
			{
				if($cgi_gene == $cnv_gene)
				{	
					//alteration types must match
					if($cgi_alteration_type != $cnv_alteration_type)
					{
						continue;
					}
					
					if($new_genes[$j] == "")
					{
						$new_genes[$j] = $cgi_gene;
						$new_driver_statement[$j] = $cgi_driver_statement;
						$new_gene_role[$j] = $cgi_gene_role;
					} else {
						$new_genes[$j] = $new_genes[$j].','.$cgi_gene;
						$new_driver_statement[$j] = $new_driver_statement[$j] .','. $cgi_driver_statement;
						$new_gene_role[$j] = $new_gene_role[$j] . ',' . $cgi_gene_role;					
					}
				}
			}
			
		}
	}

	$output = $cnv_input;

	//remove old CGI data

	if($output->getColumnIndex("CGI_drug_assoc",false,false) !== false)
	{
		$output->removeCol($output->getColumnIndex("CGI_drug_assoc"));
	}
	if($output->getColumnIndex("CGI_evid_level",false,false) !== false)
	{
		$output->removeCol($output->getColumnIndex("CGI_evid_level"));
	}
	if($output->getColumnIndex("CGI_genes",false,false) !== false)
	{
		$output->removeCol($output->getColumnIndex("CGI_genes"));
	}
	if($output->getColumnIndex("CGI_driver_statement",false,false) !== false)
	{
		$output->removeCol($output->getColumnIndex("CGI_driver_statement"));
	}
	if($output->getColumnIndex("CGI_gene_role",false,false) !== false)
	{
		$output->removeCol($output->getColumnIndex("CGI_gene_role"));
	}

	//add CGI data
	$cancertype = $cgi_input->get(0,$cgi_input->getColumnIndex("cancer"));

	$comments = $output->getComments();
	for($i=0;$i<count($comments);++$i)
	{
		if(strpos($comments[$i],"#CGI_CANCER_TYPE") !== false)
		{
			$output->removeComment($comments[$i]);
		}
	}
	$output->addComment("#CGI_CANCER_TYPE={$cancertype}");

	$output->addCol($new_genes,"CGI_genes","Genes which were included in CancerGenomeInterpreter.org analysis.");
	$output->addCol($new_driver_statement,"CGI_driver_statement","Driver statement for cancer type $cancertype according CancerGenomeInterpreter.org");
	$output->addCol($new_gene_role,"CGI_gene_role","Gene role for cancer type $cancertype according CancerGenomeInterpreter.org");
	$output->toTSV($out);
}

if($include_ncg)
{
	if(isset($cnv_in_cgi)) //use new cgi-annotated file if new annotated
	{
		$cnv_input = Matrix::fromTSV($out);
	}
	else 
	{
		$cnv_input = Matrix::fromTSV($cnv_in);
	}
	
	$i_cgi_genes = $cnv_input->getColumnIndex("CGI_genes",false,false);
	
	if($i_cgi_genes === false) 
	{
		trigger_error("Cannot annotate file $cnv_in with TCG6.0 data because there is no column CGI_genes.",E_USER_WARNING);
		exit(1);
	}
	
	$cnv_input->removeColByName("ncg_oncogene");
	$cnv_input->removeColByName("ncg_tsg");
	
	$col_tsg = array();
	$col_oncogene = array();
	
	//Annotate per CGI gene
	for($row=0;$row<$cnv_input->rows();++$row)
	{
		$cgi_genes = explode(",",trim($cnv_input->get($row,$i_cgi_genes)));
		
		$tsg_statements = array();
		$oncogene_statements = array();
		
		foreach($cgi_genes as $cgi_gene)
		{
			$statement = ncg_gene_statements($cgi_gene);
			$tsg_statements[] = $statement["is_tsg"];
			$oncogene_statements[] = $statement["is_oncogene"];
		}
		$col_oncogene[] = implode(",",$oncogene_statements);
		$col_tsg[] = implode(",",$tsg_statements);
	}
	$cnv_input->addCol($col_oncogene,"ncg_oncogene","1:gene is oncogene according NCG6.0, 0:No oncogene according NCG6.0, na: no information available about gene in NCG6.0. Order is the same as in column CGI_genes.");
	$cnv_input->addCol($col_tsg,"ncg_tsg","1:gene is TSG according NCG6.0, 0:No TSG according NCG6.0, na: no information available about gene in NCG6.0. Order is the same as in column CGI_genes.");
	
	$cnv_input->toTSV($out);
}
?>