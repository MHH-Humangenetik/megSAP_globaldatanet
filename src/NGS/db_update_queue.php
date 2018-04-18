<?php 
/** 
	@page db_update_queue
*/

require_once(dirname($_SERVER['SCRIPT_FILENAME'])."/../Common/all.php");

$parser = new ToolBase("db_update_queue", "Queues an analysis in the NGSD.");
$parser->addFlag("debug", "Debug mode: Debug output and no changes to the SGE queue.");
$parser->addEnum("db",  "Database to connect to.", true, db_names(), "NGSD");
extract($parser->parse($argv));

function load_sge_output($job_id)
{
	$base = sys_get_temp_dir()."/megSAP_sge_job_{$job_id}";
	$output = array();
	
	if (file_exists($base.".out"))
	{
		$output = array_merge($output, file($base.".out"));
	}
	
	if (file_exists($base.".err"))
	{
		$output = array_merge($output, file($base.".err"));
	}
	
	return $output;
}

function add_history_entry($job_id, &$db_conn, $status, $output = "")
{
	if (is_array($output))
	{
		$output = implode("\n", $output);
	}
	
	$db_conn->executeStmt("INSERT INTO `analysis_job_history`(`analysis_job_id`, `time`, `user_id`, `status`, `output`) VALUES ({$job_id}, '".get_timestamp(false)."', NULL, :status, :output)", array("status"=>$status, "output"=>$output));
}

function start_analysis($job_info, &$db_conn, $debug)
{
	//init
	$job_id = $job_info['id'];
	$type = $job_info['type'];
	$high_priority = $job_info['high_priority'];
	$timestamp = $debug ? "20000101000000" : date("YmdHis");
	if ($debug) print "Starting job $job_id ($type)\n";
	
	//extract sample infos from NGSD
	$sample_infos = array();
	foreach($job_info['samples'] as $sample)
	{
		list($sample, $info) = explode("/", $sample);
		$sample_info = get_processed_sample_info($db_conn, $sample);
		
		//check sample folder exists
		$sample_folder = $sample_info['ps_folder'];
		if(!is_dir($sample_folder))
		{
			if ($debug) print "  Error: Sample folder not found: {$sample_folder}\n";
			add_history_entry($job_id, $db_conn, 'error', "Error while submitting analysis to SGE: Sample folder not found: {$sample_folder}");
			return;
		}
		
		$sample_infos[] = $sample_info;
	}
	
	//get project folder from first sample
	$project_folder = $sample_infos[0]['project_folder'];
	
	//determine usable queues
	$queues = explode(",", get_path("queues_default"));
	if($high_priority)
	{
		$queues = array_merge($queues, explode(",", get_path("queues_high_priority")));
	}
	
	//slots to use
	$slots = "";
	
	//threads to use
	$threads = 2;
	
	if ($type=="single sample")
	{
		$sys_type = $sample_infos[0]['sys_type'];
		$sample_folder = $sample_infos[0]['ps_folder'];
		$sample = $sample_infos[0]['ps_name'];
		
		if ($sys_type=="RNA") //RNA
		{
			//only high_mem queues for RNA
			$queues = explode(",", get_path("queues_high_mem"));
	
			$script = "analyze_rna.php";
			$args = "-folder {$sample_folder} -name {$sample} --log {$sample_folder}analyze_rna_{$timestamp}.log";
		}
		else //DNA
		{
			//use two instead of one slots for WGS
			if ($sys_type=="WGS")
			{
				$slots = "-pe smp 2";
				$threads = 5;
			}
			
			$script = "analyze.php";
			$args = "-folder {$sample_folder} -name {$sample} --log {$sample_folder}analyze_{$timestamp}.log";	
		}
	}
	else if ($type=="trio")
	{
		for($i=0; $i<count($job_info['samples']); ++$i)
		{
			list($sample, $info) = explode("/", $job_info['samples'][$i]);
			if ($info=="child")
			{
				$c_info = $sample_infos[$i];
			}
			else if ($info=="father")
			{
				$f_info = $sample_infos[$i];
			}
			else if ($info=="mother")
			{
				$m_info = $sample_infos[$i];
			}
			else
			{
				add_history_entry($job_id, $db_conn, 'error', "Error while submitting analysis to SGE: Unknown sample info '$info' for analysis type '$type'!");
				return;
			}
		}
		
		//create output folder
		$out_folder = "{$project_folder}/Trio_".$c_info['ps_name']."_".$f_info['ps_name']."_".$m_info['ps_name']."/";
		if (!$debug && !file_exists($out_folder))
		{
			mkdir($out_folder);
			if (!chmod($out_folder, 0777))
			{
				add_history_entry($job_id, $db_conn, 'error', "Error while submitting analysis to SGE: Could not change privileges of folder: {$out_folder}");
				return;
			}
		}
		
		$script = "trio.php";
		$args = "-c ".$c_info["ps_bam"]." -f ".$f_info["ps_bam"]." -m ".$m_info["ps_bam"]." -out_folder {$out_folder} --log {$out_folder}trio.log";
	}
	else if ($type=="multi sample")
	{
		$names = array();
		$bams = array();
		$status = array();
		for($i=0; $i<count($job_info['samples']); ++$i)
		{
			list($names[], $status[]) = explode("/", $job_info['samples'][$i]);
			$bams[] = $sample_infos[$i]['ps_bam'];
		}
		
		//create output folder
		$out_folder = "{$project_folder}/Multi_".implode("_", $names)."/";
		if (!$debug && !file_exists($out_folder))
		{
			mkdir($out_folder);
			if (!chmod($out_folder, 0777))
			{
				add_history_entry($job_id, $db_conn, 'error', "Error while submitting analysis to SGE: Could not change privileges of folder: {$out_folder}");
				return;
			}
		}

		//determine command and arguments
		$script = "multisample.php";
		$args = "-bams ".implode(" ", $bams)." -status ".implode(" ", $status)." -out_folder {$out_folder} --log {$out_folder}multi.log";
	}
	else if ($type=="somatic")
	{
		for($i=0; $i<count($job_info['samples']); ++$i)
		{
			list($sample, $info) = explode("/", $job_info['samples'][$i]);
			if ($info=="tumor")
			{
				$t_info = $sample_infos[$i];
			}
			else if ($info=="normal")
			{
				$n_info = $sample_infos[$i];
			}
			else
			{
				add_history_entry($job_id, $db_conn, 'error', "Error while submitting analysis to SGE: Unknown sample info '$info' for analysis type '$type'!");
				return;
			}
		}

		//create output folder
		$out_folder = "{$project_folder}/Somatic_".$t_info['ps_name']."_".$n_info['ps_name']."/";
		if (!$debug && !file_exists($out_folder))
		{
			mkdir($out_folder);
			if (!chmod($out_folder, 0777))
			{
				add_history_entry($job_id, $db_conn, 'error', "Error while submitting analysis to SGE: Could not change privileges of folder: {$out_folder}");
				return;
			}
		}
	
		$script = "somatic_dna.php";
		$args = "-p_folder {$project_folder} -t_id ".$t_info['ps_name']." -n_id ".$n_info['ps_name']." -o_folder {$out_folder} --log {$out_folder}somatic_dna_{$timestamp}.log";
	}
	else
	{
		add_history_entry($job_id, $db_conn, 'error', "Error while submitting analysis to SGE: Unknown analysis type '$type'!");
		return;
	}
	
	//submit to queue
	$sge_output = sys_get_temp_dir()."/megSAP_sge_job_{$job_id}";
	$command_sge = "qsub -V {$slots} -b y -wd {$project_folder} -m n -M ".get_path("queue_email")." -e {$sge_output}.err -o {$sge_output}.out -q ".implode(",", $queues);
	$command_pip = "php ".repository_basedir()."/src/Pipelines/{$script} ".$job_info['args']." {$args}";
	if($debug)
	{
		 print "	SGE: {$command_sge}\n";
		 print "	PIP: {$command_pip}\n";
		 $stdout = array();
		 $stderr = array();
		 $sge_id = 4711;
	}
	else
	{
		list($stdout, $stderr) = exec2($command_sge." ".$command_pip);
		$sge_id = explode(" ", $stdout[0])[2];
	}
	
	//handle submission status
	if ($sge_id>0)
	{
		$db_conn->executeStmt("UPDATE analysis_job SET sge_id=:sge_id WHERE id=:id", array("sge_id"=>$sge_id, "id"=>$job_id)); 
		add_history_entry($job_id, $db_conn, 'started');
	}
	else
	{
		add_history_entry($job_id, $db_conn, 'error', "SGE command failed:\n{$command_sge}\n{$command_pip}\nSTDOUT:\n".implode("\n", $stdout)."\nSTDERR:\n".implode("\n", $stderr));
	}
}

function update_analysis_status($job_info, &$db_conn, $debug)
{
	$job_id = $job_info['id'];
	$type = $job_info['type'];
	$sge_id = $job_info['sge_id'];
	
	if ($debug) print "Updating status job $job_id ($type)\n";
	
	//check if job is still running
	list($stdout) = exec2("qstat -u '*' | egrep '^\s+{$sge_id}\s+' 2>&1", false);
	$finished = trim(implode("", $stdout))=="";
	
	if (!$finished) //running => update NGSD infos
	{
		$parts = explode(" ", preg_replace('/\s+/', ' ', $stdout[0]));
		$state = $parts[5];
		if ($debug) print "	Job queued/running (state {$state})\n";
				
		//update queue info
		if ($state=="r" && $job_info['sge_queue']=="")
		{
			$db_conn->executeStmt("UPDATE analysis_job SET sge_queue=:sge_queue WHERE id=:id", array("sge_queue"=>$parts[8], "id"=>$job_id));
		}
	}
	else //finished => add status in NGSD
	{
		list($stdout) = exec2("qacct -j {$sge_id} | grep failed 2>&1", false);
		$failed = ends_with(trim(implode("", $stdout)), "1");
		$status = $failed ? 'error' : 'finished';
		add_history_entry($job_id, $db_conn, $status, load_sge_output($job_id));
		if ($debug) print "	Job finished with status '{$status}'\n";
	}
}

function canceled_analysis($job_info, &$db_conn, $debug)
{
	$job_id = $job_info['id'];
	$type = $job_info['type'];
	
	if ($debug) print "Canceling job $job_id ($type)\n";
	
	//cancel job
	if ($job_info['sge_id']!="") // not started yet => nothing to cancel
	{
		if ($debug)
		{
			$stdout = array("[canceled SGE job ".$job_info['sge_id']."]");
			print "	Canceled: ".implode(" ", $stdout)."\n";
		}
		else
		{
			list($stdout) = exec2("qdel ".$job_info['sge_id']." 2>&1", false);
		}
	}
	
	//update NGSD
	add_history_entry($job_id, $db_conn, 'canceled', $stdout);
}

//abort if script is already running
$pid_file = sys_get_temp_dir()."/megSAP_db_update_pid.txt";
if (file_exists($pid_file))
{
	$pid_old = trim(file_get_contents($pid_file));
	if (posix_getpgid($pid_old)!==FALSE)
	{
		print "Script 'db_update_queue' is still running with PID '$pid_old'. Aborting!\n";
		exit();
	}
}
file_put_contents($pid_file, getmypid());

//process jobs
$db_conn = DB::getInstance($db);
$result = $db_conn->executeQuery("SELECT id FROM analysis_job ORDER BY id ASC");
foreach($result as $row)
{
	$job_info = analysis_job_info($db_conn, $row['id']);
	
	$last_status = end($job_info['history']);
	if($last_status=="queued")
	{
		start_analysis($job_info, $db_conn, $debug);
	}
	if($last_status=="started")
	{
		update_analysis_status($job_info, $db_conn, $debug);
	}
	if($last_status=="cancel")
	{
		canceled_analysis($job_info, $db_conn, $debug);
	}
}

//delete jobs that are older then three months
$days = 90;
$result = $db_conn->executeQuery("SELECT res.id FROM (SELECT j.id as id , MAX(jh.time) as last_update FROM analysis_job j, analysis_job_history jh WHERE jh.analysis_job_id=j.id GROUP BY j.id) as res WHERE res.last_update < SUBDATE(NOW(), INTERVAL $days DAY)");
foreach($result as $row)
{
	$job_id = $row['id'];
	if ($debug) print "Removing job {$job_id} because it is older than {$days} days.\n";
	$db_conn->executeStmt("DELETE FROM `analysis_job_history` WHERE analysis_job_id=$job_id");
	$db_conn->executeStmt("DELETE FROM `analysis_job_sample` WHERE analysis_job_id=$job_id");
	$db_conn->executeStmt("DELETE FROM `analysis_job` WHERE id=$job_id");
}
?>
