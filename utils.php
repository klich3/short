<?php

// Common general functions

function is_url($str = "") {
	$pat = "/^((http|https)(:[\/]{2,2}))?+([^\.][\w-\.]+[\w-]{2,6})(\/[\w- .\/\?%&=]*)?/";		     
	if(preg_match($pat, $str, $m)) {
		if($m[2] != "http" && $m[2] != "https") {
			$str = "http://".$str;
		}
		return $str;
	}
	else {
		echo ("<script type='text/javascript'>alert('wrong URL')</script>");
		$str="";
		return $str;
	}
	return false;
}

function sql_clean($str) {
	$str = mysql_real_escape_string($str);
	return $str;
}

function to_base($number, $base = 10) {
	$res = array();
	while($number >= $base) {
		array_push($res, $number % $base);
		$number = intval($number/$base);
	}
	array_push($res, $number);
	return array_reverse($res);
}

function id_to_key($id) {
	/*
	 * posibly in the future, during an instalation step, this seed shall be
	 * randomized to avoid consecuent id's
	 */
	$seed = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
	$code = to_base($id, strlen($seed));
	$ret  = "";
	foreach($code as $c) {
		$ret .= $seed[$c];
	}
	return $ret;
}

function clean_post($arr) {
	/**
	 * cleans the POST (or GET) array to just the information
	 * we need
	 */
	$vars = array(
		"max_hits",
		"notify_email"
	);
	foreach($vars as $v){
		$ret[$v] = $arr[$v];
	}
}

// Action functions

function store_url($str, $post_data, $meta_data = array()) {
	
	$url = sql_clean($str);

	//check if it is already un the database
	$sql = "SELECT url_id FROM url WHERE url = '$url'";
	$res = mysql_query($sql);
	
	//store it if it isn't
	//get the url's id
	$url_id = "";
	if (mysql_affected_rows() == 1) {
		$row = mysql_fetch_assoc($res);
		$url_id = $row["url_id"];
	} else {
		$sql = sprintf("INSERT INTO url(url) VALUES('%s')",$url);
		mysql_query($sql);
		$url_id = mysql_insert_id();
	}
	
	//create the new row in the appearances table
	//first we gather the information from the form
	$hits = isset($post_data["max_hits"]) && is_int(0 + $post_data["max_hits"])?$post_data["max_hits"]:0;
	$note = isset($post_data["notes"])?$post_data["notes"]:"";
	$mail = isset($post_data["email"])?$post_data["email"]:"";
	$stats = isset($post_data["stats"])?$post_data["stats"]:"";
	$custom = isset($post_data["custom"])?$post_data["custom"]:"";
	$exp_date = isset($post_data["exp_date"])?$post_data["exp_date"]:"";
	$exp_hour = isset($post_data["exp_hour"])?$post_data["exp_hour"]:"";
	$expiration = date_validation($exp_date, $exp_hour);

	//sanitize
	$mail = sql_clean($mail);
	$note = sql_clean($note);
	$custom = sql_clean($custom);
	$sql = sprintf(
		"INSERT INTO instance (url_id, strkey, active, max_hits, notify_email, notes, private_stats, expiration_date) ".
		"VALUES('%d','%d','1','%d','%s', '%s','%d','%s')",
		$url_id, rand(0,1000), $hits, $mail, $note, $stats, $expiration
	);
	$res = mysql_query($sql);
	if (mysql_error() || mysql_affected_rows() != 1) {
		die("can't make the insert of a new instance<br>$sql<br>".mysql_error());
	}
	
	//get a new string id for the new row
	$new_id = mysql_insert_id();
	$str_id = id_to_key($new_id);
	if($custom !=""){
		$str_id = $custom;
	}
	$sql = "UPDATE instance SET strkey = '$str_id' WHERE instance_id = $new_id";
	mysql_query($sql);
	
	//log this new creation
	store_log($new_id, "create", "ok", $meta_data);
	
	if ($mail != "") {
	
		$val_code = md5(time().rand().$mail);
		$sql = "UPDATE instance SET validation_code = '$val_code' WHERE instance_id= '$new_id'";
		mysql_query($sql);
		if (!send_activation($mail,$val_code)) {
			echo"can't send the activation email";
		}
	}
	
	
	//return the string id
	return $str_id;
}

function send_notifications($notif_mail, $notif_url, $notif_notes, $inst_id) {

	$to      = $notif_mail;
	$subject = "Your URL has been used";
	$message = "<html><head><title>Your URL has been used</title></head><body>";
	$message .= "Your URL has been used<br><br>URL: ".$notif_url."<br><br>Notes: ".$notif_notes."<br><br><br>";
	$message .= "Thank you for using our service.";
	$message .= "<br><br><br><br>if you dont want to receive more of these notifications,";
	$message .= " <a href='http://kmkz.mx/index.php?off=".$inst_id."'> click here.</a><br></body></html>";
	$headers = 'MIME-Version: 1.0' . "\r\n";
	$headers .= 'Content-type: text/html; charset=iso-8859-1' . "\r\n";
	$headers .= 'From: Kamikaze Lab URL Shortener<noreply@kamikazelab.com>' . "\r\n" .'Reply-To: noreply@kamikazelab.com'; 
	$headers .= "\r\n" .'X-Mailer: PHP/' . phpversion();
	mail ($to, $subject, $message, $headers);
}

function store_log($iid, $action, $outcome, $meta = array()){
	if(!preg_match("/create|access/",$action)) {
		return;
	}
	$ip    = isset($meta["ip"])?$meta["ip"]:"";
	$host  = isset($meta["host"])?$meta["host"]:"";
	$agent = isset($meta["agent"])?$meta["agent"]:"";
	$refer = isset($meta["referer"])?$meta["referer"]:"";
	
	$sql = sprintf("INSERT INTO log(instance_id, type, outcome, client_ip, client_host, client_agent) ".
					"VALUES(%d,'%s','%s','%s','%s','%s')",
					$iid, $action, $outcome, $ip, $host, $agent);
	mysql_query($sql);
	if (mysql_error()) {
		echo mysql_error()."\n".$sql;
	}
	return mysql_error() == "";
}

function get_url($code = "", $meta = array()){
	$ret = array(
		"code" => $code,
		"status" => "ERR",
		"url" => null,
		"cause" => "something is really fucked up with code: $code, even the error code is NaN"
	);

	if($code != "") {
		// this sql should count on log with outcome = "ok".......AND l.type = 'access'
		//sanitize
		$code = sql_clean($code);
		$sql = sprintf("
			SELECT i.instance_id AS iid, u.url AS url, i.active AS active,
				i.max_hits AS max_hits, i.notify_email AS emails, i.notes AS notes, 
				i.notifications AS notify, count(l.log_id) AS act_hits, i.expiration_date AS expiration
			FROM instance AS i
			LEFT JOIN url AS u
				ON u.url_id = i.url_id
			LEFT JOIN log AS l
				ON i.instance_id = l.instance_id
			WHERE i.strkey = '%s'
				
			GROUP BY iid
			LIMIT 1", $code);
		$res = mysql_query($sql);
		if(!mysql_error() && mysql_affected_rows() > 0){
			$row = mysql_fetch_assoc($res);
			
			//set of business logic rules
			$exp_date=strtotime($row["expiration"]);
			$today=strtotime(date("Y-m-d H:i"));
			if($row["active"] == 0) {
				$ret["cause"] = "corresponding link is not active any more";
				store_log($row["iid"],"access","error", $meta);
				return $ret;
			} elseif($row["max_hits"] > 0 && $row["act_hits"] > $row["max_hits"]) {
				$ret["cause"] = "this link had a certain number of allowed hits which has already been reached";
				store_log($row["iid"],"access","error", $meta);
				return $ret;
			} elseif($exp_date!='' && $exp_date < $today ){
				$ret["cause"] = "this link has expired";
				store_log($row["iid"],"access","error", $meta);
				return $ret;
			}
			if($row["emails"] != "" && $row["notify"] == 1) {
				send_notifications($row["emails"],$row["url"],$row["notes"], $row["iid"]);
			}
			
			$ret["status"] = "OK";
			$ret["cause"]  = "we're all right!";
			$ret["url"]    = $row["url"];
			store_log($row["iid"],"access","ok",$meta);
			return $ret;
			
		}else{
			if(mysql_affected_rows()){
				$ret["cause"] = "seems there is no url associated to this code [error code: 5]\n";
			} else {
				$ret["cause"] = "something is wrong with the db [error code: 4]$sql\n";
				if(mysql_error()) {
				    $ret["cause"] .= "\n mysql said: ".mysql_error();
				}
			}
		}
	}
	return $ret;
}

function complete_url($code) {
	return sprintf(MAIN_URL, $code);
}

function gather_meta() {
	return array(
		"ip"      => $_SERVER["REMOTE_ADDR"],
		"referer" => $_SERVER["HTTP_REFERER"],
		"agent"   => $_SERVER["REMOTE_AGENT"],
		"host"    => $_SERVER["REMOTE_HOST"]
	);
}

function get_url_info($key) {
	$ret = array(
		"status" => "ERR",
		"cause"  => "something went terribly wrong",
	);
	$sql = sprintf("
				SELECT i.instance_id AS iid, u.url AS url, 
					i.created_at AS created_at, 
					i.notes AS notes, count(it.instance_id) AS total, 
					u.url_id AS uid
				FROM instance AS i
				LEFT JOIN url as u
					ON u.url_id = i.url_id
				LEFT JOIN instance as it
					ON it.url_id = i.url_id
				WHERE i.strkey = '%s'
				GROUP BY i.instance_id
			",
			$key);
	$res = mysql_query($sql);
	$row = mysql_fetch_assoc($res);
	if(!mysql_error() && mysql_affected_rows() > 0) {
		$ret["status"]     = "OK";
		$ret["url"]        = $row["url"];
		$ret["count"]      = $row["total"];
		$ret["created_at"] = $row["created_at"];
		$ret["notes"]      = $row["notes"];
		$ret["iid"]        = $row["iid"];
		$ret["log"]        = array();
		unset($ret["cause"]);

		$sql = sprintf("
					SELECT type, tstamp 
					FROM log
					WHERE instance_id = %d",
					$row["iid"]);

		$res = mysql_query($sql);

		$i = 0;

		while($row = mysql_fetch_assoc($res)) {
			$ret["log"][$i++] = array(
				"type" => $row["type"],
				"tstamp" => $row["tstamp"]
			);
		}
	}
	return $ret;
}





function send_activation($mail,$val_code) {

	$to      = $mail;
	$subject = "Kamikaze Lab Url Shortener activation";
	$message = "<html><head><title>Activate your email address</title></head><body>";
	$message .= "<br><br>Please activate your email address in order to receive notifications abour your ";
	$message .= "url by clicking the following link:\r <br><br>";
	$message .= "http://kmkz.mx/index.php?val=".$val_code."\r\r<br><br></body></html>";
	$headers = 'MIME-Version: 1.0' . "\r\n";
	$headers .= 'Content-type: text/html; charset=iso-8859-1' . "\r\n";
	$headers .= 'From: Kamikaze Lab URL Shortener <noreply@kamikazelab.com>' . "\r\n" .'Reply-To: noreply@kamikazelab.com'; 
	$headers .= "\r\n" .'X-Mailer: PHP/' . phpversion();
	mail ($to, $subject, $message, $headers);
	return true;
}

function activate_email($val_code) {
	//sanitize
	$val_code = sql_clean($val_code);
	$sql = "SELECT instance_id FROM instance WHERE validation_code = '$val_code'";
	
	$res = mysql_query($sql);
	if(mysql_num_rows($res) != 0) {
	$inst_id = mysql_result($res,0);
	$sql = "UPDATE instance SET notifications = 1 WHERE instance_id = '$inst_id'";
		
		if(!mysql_query($sql)) {
			die("can't validate your email<br><br>".mysql_error());
		}
		echo "<script type='text/javascript'>alert('your email address has been validated')</script>";
		return true;
	} else {
	echo "<script type='text/javascript'>alert('Something went wrong, cannot validate your email')</script>";
	}
}

function is_email($mail) {

	if ($mail == "") {
	return true;
	}

	$mail_pattern = "/^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,4})$/";
	if(!preg_match($mail_pattern, $mail)) {
		echo "<script type='text/javascript'>alert('your email address is incorrect')</script>";
		return false;
		} 
	else {
		$kamikaze_pattern = "/^[_a-z0-9-]+(\.[_a-z0-9-]+)*@kamikazelab.com$/";
		if(!preg_match($kamikaze_pattern, $mail)){
			echo "<script type='text/javascript'>alert('sorry, this service is only available for Kamikaze Lab users')</script>";
			return false;
		}
		else
		return true;
	}
}
    
function generate_xml($array) {
	$xml = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n";
	$xml .= '<url>'."\n";
	$xml .= fill_xml($array);
	$xml .= '</url>'. "\n";
	return $xml;
}

function fill_xml($array) {
	$xml = '';
	if (is_array($array) || is_object($array)) {
		foreach ($array as $key=>$value) {
		
			$xml .= '<' . $key . '>' . "\n" . fill_xml($value) . '</' . $key . '>' . "\n";
		}
	} else {
		$xml = htmlspecialchars($array, ENT_QUOTES) . "\n";
	}

	return $xml;
}

function turn_off_notif($inst_id){

	//sanitize
	$inst_id = sql_clean($inst_id);
	$sql = sprintf("SELECT instance_id FROM instance WHERE instance_id = '%s'",$inst_id);
	$res = mysql_query($sql);
	if(mysql_num_rows($res) != 0) {
		$inst_id = mysql_result($res,0);
		$sql = "UPDATE instance SET notifications = 0 WHERE instance_id = '$inst_id'";
		if(!mysql_query($sql)) {
			die("something went wrong with the data base".mysql_error());
		}
		echo "<script type='text/javascript'>alert('you will not receive more notifications from this URL')</script>";
		return true;
	}else 
	echo "<script type='text/javascript'>alert('Something went wrong, cannot find your email')</script>";
	
}

function is_private ($key) {
	$sql = "SELECT private_stats FROM instance WHERE strkey = '$key'";
	$res = mysql_query($sql);
	if(mysql_result($res,0)==1)
		return true;
	else
		return false;	
}

function is_available ($custom_url) {
	//sanitize
	$custom_url = sql_clean($custom_url);
	$sql = sprintf("SELECT instance_id FROM instance WHERE strkey = '%s'",$custom_url);
	$res = mysql_query($sql);
	if(mysql_result($res,0)!=0) {
		echo "<script type='text/javascript'>alert('That URL already exists.')</script>";
		return false;
	}	
	else 
		return true;

}
function date_validation ($date, $hour) {
	$today = getdate();
	list($y,$m,$d) = explode('/',$date);
	if($date == "") 
		$expiration = NULL;

	elseif(substr_count($date,'/') == 2 && checkdate($m,$d,$y) && $y>=$today['year']) 
     	 $expiration = $date.' '.$hour;
            			
    else {
    	echo "<script type='text/javascript'>alert('Invalid Date!')</script>";
    	return 'error';
    }
    

	return $expiration;
}


?>