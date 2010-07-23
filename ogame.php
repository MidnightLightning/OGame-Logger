<?php
/**
 * OGame stats tracker
 *
 * Keeps track of Espionage and Attack results for you
 */

date_default_timezone_set('Etc/GMT-2'); // Set timezone for OGame servers

$db = new SQLiteDatabase('ogame.db');
@$db->queryExec('CREATE TABLE locations (name STRING, location STRING, type STRING, player STRING);');
@$db->queryExec('CREATE TABLE resources (location INTEGER, updated INTEGER, metal INTEGER, crystal INTEGER, deuterium INTEGER);', $err);


if (!empty($_POST['data'])) {
	// Parse data
	$data = explode("\n", $_POST['data']);
	$parsed = array();
	foreach($data as $i => $line) {
		$line = trim($line); // Remove whitespace
		if (substr($line,0,5) == 'Date:') {
			// This is the date of the message
			$date = trim(substr($line,5));
			list($date, $time) = explode(" ", $date);
			list ($day, $month, $year) = explode(".", $date);
			list ($h, $m, $s) = explode(":", $time);
			$ts = mktime($h, $m, $s, $month, $day, $year);
			$parsed['updated'] = (!$ts)? time() : $ts;
			$parsed['updated_std'] = date('c', $parsed['updated']);
		} elseif (substr($line,0,8) == 'Subject:') {
			// Subject of the message
			$line = trim(substr($line,8)); // Trim off leader
			if (substr($line,0,9) == 'Espionage') {
				$parsed['action'] = 'espionage';
				$location = substr($line,20);
				$pos = strpos($location, "[");
				$parsed['name'] = substr($location,0,$pos-1);
				$parsed['location'] = substr($location,$pos+1, -1);
			} elseif (substr($line,0,6) == 'Combat') {
				$parsed['action'] = 'attack';
			} else {
				$parsed['action'] = "unknown";
			}
		} elseif (substr($line,0, 10) == 'Combat at ') {
			$tmp = explode(" ", substr($line, 10));
			$parsed['name'] = $tmp[0];
			$parsed['location'] = substr($tmp[1], 1, -1);
		} elseif (substr($line,0,6) == 'Loot :') {
			$line = trim(substr($line,6));
			$tmp = explode(" ", $line);
			$parsed['metal'] = str_replace(".", "", $tmp[0]);
			$parsed['crystal'] = str_replace(".", "", $tmp[2]);
			$parsed['deuterium'] = str_replace(".", "", $tmp[5]);
		} elseif (false !== $pos = strpos($line, '(Players ')) {
			$end = strpos($line, ')', $pos);
			$parsed['player'] = substr($line, $pos+10, $end-$pos-11);
		} elseif (substr($line, 0, 6) == 'Metal:') {
			// Metal/Crystal line
			$line = preg_replace("/\s+/", ' ', $line);
			$tmp = explode(" ", $line);
			$parsed['metal'] = str_replace(".", "", $tmp[1]);
			$parsed['crystal'] = str_replace(".", "", $tmp[3]);
		} elseif (substr($line, 0, 10) == 'Deuterium:') {
			// Deuterium/Energy line
			$line = preg_replace("/\s+/", ' ', $line);
			$tmp = explode(" ", $line);
			$parsed['deuterium'] = str_replace(".", "", $tmp[1]);
		}
	}
	if (!empty($parsed['name']) && !empty($parsed['location'])) {
		// See if this location is in the system yet
		$rs = $db->query("SELECT ROWID FROM locations WHERE name='{$parsed['name']}' AND location='{$parsed['location']}';");
		if ($rs->numRows() == 0) {
			// Add this location
			$rs = $db->queryExec("INSERT INTO locations (name, location, type, player) VALUES ('{$parsed['name']}', '{$parsed['location']}', 'planet', '{$parsed['player']}');");
			$loc_id = $db->lastInsertRowid();
		} else {
			$loc_id = $rs->fetchSingle();
		}
	}
	
	if ($parsed['action'] == 'espionage') {
		// Replace data with this result
		$rs = $db->query("SELECT ROWID FROM resources WHERE location={$loc_id} AND updated={$parsed['updated']};");
		if ($rs->numRows() == 0) {
			$rs = $db->queryExec("INSERT INTO resources (location, updated, metal, crystal, deuterium) VALUES ({$loc_id}, {$parsed['updated']}, {$parsed['metal']}, {$parsed['crystal']}, {$parsed['deuterium']});");
		}
	} elseif ($parsed['action'] == 'attack') {
		// Subtract loot from planet's resources
		$rs = $db->query("SELECT ROWID FROM resources WHERE location={$loc_id} AND updated={$parsed['updated']};");
		if ($rs->numRows() == 0) {
			$rs = $db->query("SELECT metal, crystal, deuterium FROM resources WHERE location={$loc_id} AND updated<{$parsed['updated']} ORDER BY updated DESC LIMIT 1;");
			$rs = $rs->fetch(SQLITE_ASSOC);
			$metal = $rs['metal'] - $parsed['metal'];
			$crystal = $rs['crystal'] - $parsed['crystal'];
			$deuterium = $rs['deuterium'] - $parsed['deuterium'];
			$rs = $db->queryExec("INSERT INTO resources (location, updated, metal, crystal, deuterium) VALUES ({$loc_id}, {$parsed['updated']}, {$metal}, {$crystal}, {$deuterium});");
		}		
	}
	debuglog(var_export($parsed, true));
	debuglog(var_export($data, true));
}

echo "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n";
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
 
<head>
	<title>OGame Logger</title>
	<meta http-equiv="content-type" content="text/html;charset=utf-8" />
	<meta http-equiv="content-style-type" content="text/css" />
	<style>
		body { background: url('http://uni101.ogame.us/game/img/background/background_voll_2.jpg') no-repeat scroll 50% -150px #000000; color:#FFFFFF;}
		body, p, td, th, div { font-size:10pt; }
		th { text-align:left;}
		td.num { text-align:right;}
		p { margin:0 0 0.5em 0; padding:0; }
		a { color:#AAA; text-decoration:none; }
		a:hover { text-decoration: underline; }
		textarea { background-color:#000; color:#FFF; border:solid 1px #666; }
		
		div#wrapper { width:960px; padding:0; margin:0 auto; }
		div.block { background-color: rgba(80,80,80, 0.6); border:solid 2px #666; padding:10px; margin:5px 0; -moz-border-radius:5px;}
	</style>
	<script src="http://www.google.com/jsapi"></script>
	<script type="text/javascript">
		google.load("jquery", "1.3.2");
	</script>
	<script type="text/javascript">
		$(document).ready(function() {
			console.log("Parsing links");
			$("a[rel*='external']").click(function(event) {
				event.preventDefault(); // Keep from following standard href of link
				new_win = window.open($(this).attr('href'), 'offsite_popup') // Pop up a window to that URL
				if (window.focus) { new_win.focus() } // Give it focus if possible
			});
		});
</script>
</head>
 
<body>
<div id="wrapper">
<div class="block"><form action="<?=$_SERVER['PHP_SELF']?>" method="post"><textarea name="data" style="width:100%; height:100px;"></textarea><input type="submit" value="Parse" /></form>
<p><a href="http://www.jimmywest.se/other/calcogame/index.php?page=Reports" rel="external">JimmyWest Parser</a></p></div>

<div class="block">
<p style="margin:0; font-size:1.5em; font-weight:bold; text-align:center;">Game time: <?php echo date("d.m.Y H:i:s"); ?></h1>
</div>

<?php
if (!empty($_GET['l'])):
    ///// Show only one Location's data /////
	$location = $db->query("SELECT * FROM locations WHERE ROWID={$_GET['l']} LIMIT 1;");
	$location = $location->fetch(SQLITE_ASSOC);
	
	$rs = $db->query("SELECT max(updated), min(updated), max(metal), min(metal), max(crystal), min(crystal), max(deuterium), min(deuterium) FROM resources WHERE location={$_GET['l']};");
	$rs = $rs->fetch(SQLITE_NUM);
	$ts_max = $rs[0];
	$ts_min = $rs[1];
	$max_m = $rs[2]+100;
	$min_m = $rs[3]-100;
	$max_c = $rs[4]+100;
	$min_c = $rs[5]-100;
	$max_d = $rs[6]+100;
	$min_d = $rs[7]-100;
	if ($ts_max != $ts_min) {
		$x_labels = $x_positions = array();
		$ts_delta = $ts_max-$ts_min;
		if ($ts_delta < 60*60*3) {
			// Time frame is in minutes
		} elseif ($ts_delta < 60*60*48) {
			// Time frame is in hours
			$ts_first = mktime(date('H', $ts_min), 0, 0, date('n', $ts_min), date('j', $ts_min), date('Y', $ts_min)); // When did the hour containing $ts_min start?			
			$increment = 60*60;
			if ($ts_delta < 60*60*8) {
				$increment = $increment*1;
			} elseif ($ts_delta < 60*60*18) {
				$increment = $increment*2;
			} else {
				$increment = $increment*4;
			}
			list ($x_labels, $x_positions) = date_labels($ts_first+60*60, $increment, $ts_min, $ts_max, "H:i");
		} else {
			// Time frame is in days
			$ts_first = mktime(0,0,0, date('n', $ts_min), date('j', $ts_min), date('Y', $ts_min)); // When did the day containing $ts_min start?
			list ($x_labels, $x_positions) = date_labels($ts_first+60*60, 60*60*24, $ts_min, $ts_max, "d.m.y");
		}
		$x_axis = (count($x_positions) > 0)? "&chxp=0,".implode(',', $x_positions)."&chxl=0:|".implode("|", $x_labels)."&chxtc=0,5" : "";
		$metal = $db->arrayQuery("SELECT metal, updated FROM resources WHERE location={$_GET['l']} ORDER BY updated ASC;", SQLITE_NUM);
		$crystal = $db->arrayQuery("SELECT crystal, updated FROM resources WHERE location={$_GET['l']} ORDER BY updated ASC;", SQLITE_NUM);
		$deuterium = $db->arrayQuery("SELECT deuterium, updated FROM resources WHERE location={$_GET['l']} ORDER BY updated ASC;", SQLITE_NUM);
		$url_m = $url_c = $url_d = "http://chart.apis.google.com/chart?cht=lxy&chs=300x125&chxt=x,y{$x_axis}&chf=bg,s,000000&chxs=0,CCCCCC,1,CCCCCC&chts=CCCCCC,14&chd=t:";
		$values_m = $values_c = $values_d = array();
		$dates_m = $dates_c = $dates_d = array();
		foreach($metal as $row) {
			$dates_m[] = sprintf("%01.4f", ($row['1']-$ts_min)*100/$ts_delta);
			$values_m[] = $row['0'];
		}
		foreach($crystal as $row) {
			$dates_c[] = sprintf("%01.4f", ($row['1']-$ts_min)*100/$ts_delta);
			$values_c[] = $row['0'];
		}
		foreach($deuterium as $row) {
			$dates_d[] = sprintf("%01.4f", ($row['1']-$ts_min)*100/$ts_delta);
			$values_d[] = $row['0'];
		}
		$url_m .= implode(',', $dates_m).'|'.implode(',', $values_m)."&chds=0,100,{$min_m},{$max_m}&chxr=1,{$min_m},{$max_m}&chco=CCCCCC&chm=B,333333,0,0,0&chtt=Metal";
		$url_c .= implode(',', $dates_c).'|'.implode(',', $values_c)."&chds=0,100,{$min_c},{$max_c}&chxr=1,{$min_c},{$max_c}&chco=6666FF&chm=B,000099,0,0,0&chtt=Crystal";
		$url_d .= implode(',', $dates_d).'|'.implode(',', $values_d)."&chds=0,100,{$min_d},{$max_d}&chxr=1,{$min_d},{$max_d}&chco=33FFFF&chm=B,006666,0,0,0&chtt=Deuterium";
	} else {
		// Only one data entry
		$url_m = $url_c = $url_d = "";
	}
?>

<div class="block">
<p>&lArr; <a href="<?=$_SERVER['PHP_SELF']?>">Home</a></p>
<p><?=$location['name']?> [<?=$location['location']?>]</p>
<?php if (!empty($url_m)) {
echo "<p style=\"text-align:center\"><img src=\"{$url_m}\" alt=\"\" /><img style=\"margin:0 5px;\" src=\"{$url_c}\" alt=\"\" /><img src=\"{$url_d}\" alt=\"\" /></p>\n";
}?>
</div>

<?php
else:
	///// Show listing of all Locations /////
	if (empty($_GET['sort'])) $_GET['sort'] = "";
	switch($_GET['sort']) {
		case 'updated':
			$sort = 'r.updated DESC';
			break;
		case 'locname':
			$sort = 'l.name';
			break;
		case 'location':
			$sort = 'l.location';
			break;
		case 'player':
			$sort = 'l.player';
			break;
		case 'metal':
			$sort = 'r.metal DESC';
			break;
		case 'crystal':
			$sort = 'r.crystal DESC';
			break;
		case 'deuterium':
			$sort = 'r.deuterium DESC';
			break;
		default:
			$sort = 'l.location';
	}
	$locations = $db->arrayQuery("SELECT r.*, l.*, x.recordnum FROM (SELECT location, max(updated) as maxupdated, count(*) AS recordnum FROM resources GROUP BY location) x LEFT JOIN resources r ON r.updated=x.maxupdated AND r.location=x.location LEFT JOIN locations l ON l.ROWID=r.location ORDER BY {$sort};", SQLITE_ASSOC);
?>

<div class="block">
<table style="width:100%" cellspacing="0" cellpadding="3">
<thead>
<tr><th><a href="<?=$_SERVER['PHP_SELF']?>?sort=updated">Updated</a></th><th><a href="<?=$_SERVER['PHP_SELF']?>?sort=locname">Name</a></th><th><a href="<?=$_SERVER['PHP_SELF']?>?sort=location">Location</a></th><th><a href="<?=$_SERVER['PHP_SELF']?>?sort=player">Player</a></th><th style="text-align:right"><a href="<?=$_SERVER['PHP_SELF']?>?sort=metal">Metal</a></th><th style="text-align:right"><a href="<?=$_SERVER['PHP_SELF']?>?sort=crystal">Crystal</a></th><th style="text-align:right"><a href="<?=$_SERVER['PHP_SELF']?>?sort=deuterium">Deuterium</a></th></tr>
</thead>
<tbody>
<?php
foreach($locations as $location) {
	$date = time()-$location['r.updated'];
	$date = $date/60;
	$row_color = "inherit";
	if ($date < 60) {
		$date = "<span style=\"color:#00FF00\">".floor($date)." min. ago</span>";
		$row_color = "rgba(0,255,0,0.1)";
	} elseif ($date < 60*24) {
		$date = "<span style=\"color:#FFFF00\">".floor($date/60)." hrs. ago</span>";
		$row_color = "rgba(255,255,0,0.1)";
	} else {
		$date = "<span style=\"color:#FF3333\">".floor($date/60/24)." days ago</span>";
		$row_color = "rgba(255,0,0,0.1)";
	}
	echo "<tr style=\"background-color:{$row_color}\"><td>{$date} ({$location['x.recordnum']} records total)</td><td><a href=\"{$_SERVER['PHP_SELF']}?l={$location['r.location']}\">{$location['l.name']}</a></td><td>{$location['l.location']}</td><td>{$location['l.player']}</td><td class=\"num\">".number_format($location['r.metal'])."</td><td class=\"num\">".number_format($location['r.crystal'])."</td><td class=\"num\">".number_format($location['r.deuterium'])."</td></tr>\n";
}
?>
</tbody>
</table>
</div>

<?php
endif;
?>

</div>
</body>
</html>
<?php
///// Functions /////
function debuglog($msg) {
	if (isset($_GET['debug'])) echo $msg;
}

function date_labels($ts_start, $increment, $ts_min, $ts_max, $format) {
	$labels = $positions = array();
	while ($ts_start < $ts_max) {
		$labels[] = date($format, $ts_start);
		$positions[] = sprintf("%01.2f", ($ts_start-$ts_min)/($ts_max-$ts_min)*100);
		$ts_start += $increment;
	}
	return array($labels, $positions);
}
