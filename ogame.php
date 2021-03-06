<?php
/**
 * OGame stats tracker
 *
 * Keeps track of Espionage and Attack results for you
 */

session_start(); // Track user state
date_default_timezone_set('Etc/GMT-2'); // Set timezone for OGame servers

$db = new SQLiteDatabase('ogame.db');
@$db->queryExec('CREATE TABLE locations (name STRING, location STRING, type STRING, player STRING);');
@$db->queryExec('CREATE TABLE resources (location INTEGER, updated INTEGER, metal INTEGER, crystal INTEGER, deuterium INTEGER, fleet TEXT, defense TEXT, buildings TEXT, research TEXT);', $err);

/**
 * Parse text data from Espionage or Attack Reports
 */
if (!empty($_POST['data'])) {
	$data = explode("\n", $_POST['data']);
	$parsed = array();
	foreach($data as $i => &$line) {
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
			if (substr(trim(substr($line,8)),0,9) == 'Espionage') {
				$parsed['action'] = 'espionage';
				$location = substr($line,30);
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
			$tmp = explode(" ", trim(substr($line,6)));
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
		} elseif ($line == 'fleets') {
			// Lines up until "Defense" are about fleets
			$j = $i+1;
			$ships = array();
			while ($j < count($data) && trim($data[$j]) != 'Defense') {
				preg_match_all('/([\w ]+)\s+(\d+)/', $data[$j], $matches);
				$ships[$matches[1][0]] = $matches[2][0];
				if (isset($matches[1][1])) $ships[$matches[1][1]] = $matches[2][1];
				$j++;
			}
			$parsed['fleet'] = $ships;
		} elseif ($line == 'Defense') {
			// Lines up until "Building" are about defenses
			$j = $i+1;
			$defenses = array();
			while ($j < count($data) && trim($data[$j]) != 'Building') {
				preg_match_all('/([\w ]+)\s+(\d+)/', $data[$j], $matches);
				$defenses[$matches[1][0]] = $matches[2][0];
				if (isset($matches[1][1])) $defenses[$matches[1][1]] = $matches[2][1];
				$j++;
			}
			$parsed['defense'] = $defenses;
		} elseif ($line == 'Building') {
			// Lines up until "Research" are about buildings
			$j = $i+1;
			$buildings = array();
			while ($j < count($data) && trim($data[$j]) != 'Research') {
				preg_match_all('/([\w ]+)\s+(\d+)/', $data[$j], $matches);
				$buildings[$matches[1][0]] = $matches[2][0];
				if (isset($matches[1][1])) $buildings[$matches[1][1]] = $matches[2][1];
				$j++;
			}
			$parsed['buildings'] = $buildings;
		} elseif ($line == 'Research') {
			// Lines up until "counter-espionage" are about Research
			$j = $i+1;
			$research = array();
			while ($j < count($data) && substr(trim($data[$j]),0,27) != 'Chance of counter-espionage') {
				preg_match_all('/([\w ]+)\s+(\d+)/', $data[$j], $matches);
				$research[$matches[1][0]] = $matches[2][0];
				if (isset($matches[1][1])) $research[$matches[1][1]] = $matches[2][1];
				$j++;
			}
			$parsed['research'] = $research;
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
			$fleet = (isset($parsed['fleet']))? serialize($parsed['fleet']) : '';
			$defense = (isset($parsed['defense']))? serialize($parsed['defense']) : '';
			$buildings = (isset($parsed['buildings']))? serialize($parsed['buildings']) : '';
			$research = (isset($parsed['buildings']))? serialize($parsed['research']) : '';
			$rs = $db->queryExec("INSERT INTO resources (location, updated, metal, crystal, deuterium, fleet, defense, buildings, research) VALUES ({$loc_id}, {$parsed['updated']}, {$parsed['metal']}, {$parsed['crystal']}, {$parsed['deuterium']}, '{$fleet}', '{$defense}', '{$buildings}', '{$research}');");
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
<div class="block"><form action="<?php echo $_SERVER['PHP_SELF']; if (isset($_GET['debug'])) echo "?debug"; ?>" method="post"><input type="hidden" name="<?php echo session_name(); ?>" value="<?php echo session_id(); ?>" /><textarea name="data" style="width:100%; height:100px;"></textarea><input type="submit" value="Parse" /></form>
<p><a href="http://www.jimmywest.se/other/calcogame/index.php?page=Reports" rel="external">JimmyWest Parser</a></p><!-- An additional Espionage Parser, for additional data from the report -->
</div>

<div class="block">
<p style="margin:0; font-size:1.5em; font-weight:bold; text-align:center;">Game time: <?php echo date("d.m.Y H:i:s"); ?></h1>
</div>

<?php
if (!empty($_GET['l'])):
	///// Show only one Location's data /////
	if (!empty($_GET['action'])) {
		if ($_GET['action'] == 'deactivate') {
			$db->queryExec("UPDATE locations SET type='inactive' WHERE ROWID={$_GET['l']};");
		} elseif ($_GET['action'] == 'activate') {
			$db->queryExec("UPDATE locations SET type='planet' WHERE ROWID={$_GET['l']};");
		}
	}
	
	$location = $db->query("SELECT * FROM locations WHERE ROWID={$_GET['l']} LIMIT 1;");
	$location = $location->fetch(SQLITE_ASSOC);
	
	$ts_max = $db->query("SELECT updated FROM resources WHERE location={$_GET['l']} ORDER BY updated DESC LIMIT 1;");
	$ts_max = $ts_max->fetchSingle();
	
	$ts_limit = $ts_max - 60*60*24*5; // Maximum of five days ago
	$rs = $db->query("SELECT max(updated), min(updated), max(metal), min(metal), max(crystal), min(crystal), max(deuterium), min(deuterium) FROM resources WHERE location={$_GET['l']} AND updated>{$ts_limit};");
	$rs = $rs->fetch(SQLITE_NUM);
	$ts_max = $rs[0];
	$ts_min = $rs[1];
	$max_m = $rs[2]+1000;
	$min_m = $rs[3]-1000;
	$max_c = $rs[4]+1000;
	$min_c = $rs[5]-1000;
	$max_d = $rs[6]+1000;
	$min_d = $rs[7]-1000;
	if ($min_m < 0) $min_m = 0;
	if ($min_c < 0) $min_c = 0;
	if ($min_d < 0) $min_d = 0;
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
			} elseif ($ts_delta < 60*60*24) {
				$increment = $increment*4;
			} else {
				$increment = $increment*12;
			}
			list ($x_labels, $x_positions) = date_labels($ts_first+60*60, $increment, $ts_min, $ts_max, "H:i");
		} else {
			// Time frame is in days
			$ts_first = mktime(0,0,0, date('n', $ts_min), date('j', $ts_min), date('Y', $ts_min)); // When did the day containing $ts_min start?
			list ($x_labels, $x_positions) = date_labels($ts_first+60*60*24, 60*60*24, $ts_min, $ts_max, "d.m.y");
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
<p><? if ($location['type'] == "planet") {
	echo "<a href=\"".$_SERVER['PHP_SELF']."?l=".$_GET['l']."&action=deactivate\">Deactivate this planet</a>";
} else { 
	echo "<a href=\"".$_SERVER['PHP_SELF']."?l=".$_GET['l']."&action=activate\">Re-activate this planet</a>";
}?></p>
<?php if (!empty($url_m)) {
echo "<p style=\"text-align:center\"><img src=\"{$url_m}\" alt=\"\" /><img style=\"margin:0 5px;\" src=\"{$url_c}\" alt=\"\" /><img src=\"{$url_d}\" alt=\"\" /></p>\n";
}?>
</div>

<?php
else:
	///// Show listing of all Locations /////
	if (empty($_GET['sort'])) {
		if (empty($_SESSION['sort'])) {
			// No sort specified; fill in default
			$_GET['sort'] = "";
			$_SESSION['sort'] = "";
		} else {
			$_GET['sort'] = $_SESSION['sort']; // No sort specified for this request; fill in from session data
		}
	} else {
		$_SESSION['sort'] = $_GET['sort']; // New sort parameter specified, fill in session data
	}
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
	$where = (!empty($_GET['filter']) && $_GET['filter'] == 'inactive')? "" : "WHERE l.type='planet' ";
	$locations = $db->arrayQuery("SELECT r.*, l.*, x.recordnum FROM (SELECT location, max(updated) as maxupdated, count(*) AS recordnum FROM resources GROUP BY location) x LEFT JOIN resources r ON r.updated=x.maxupdated AND r.location=x.location LEFT JOIN locations l ON l.ROWID=r.location {$where}ORDER BY {$sort};", SQLITE_ASSOC);
	
	$ts_now = time();
	foreach($locations as &$location) {
		// Get most recent fleet, defense, building, and research data
		$location['r.fleet'] = most_recent_of('fleet', $location['r.location']);
		$location['r.defense'] = most_recent_of('defense', $location['r.location']);
		$location['r.buildings'] = most_recent_of('buildings', $location['r.location']);
		$location['r.research'] = most_recent_of('research', $location['r.location']);
			
		// Determine up/down trending
		if ($location['x.recordnum'] > 1) {
			$tmp = $db->query("SELECT * FROM resources WHERE location={$location['r.location']} AND updated<{$location['r.updated']} ORDER BY updated DESC LIMIT 1");
			$tmp = $tmp->fetch(SQLITE_ASSOC);
			if ($tmp['metal'] > $location['r.metal']) {
				$location['r.metal_formatted'] = "<span title=\"Decrease since last\" style=\"color:#FF9999\">".number_format($location['r.metal'])."</span>";
			} elseif ($tmp['metal'] < $location['r.metal']) {
				$location['r.metal_formatted'] = "<span title=\"Increase since last\" style=\"color:#99FF99\">".number_format($location['r.metal'])."</span>";
			} else {
				$location['r.metal_formatted'] = number_format($location['r.metal']);
			}
			if ($tmp['crystal'] > $location['r.crystal']) {
				$location['r.crystal_formatted'] = "<span title=\"Decrease since last\"  style=\"color:#FF9999\">".number_format($location['r.crystal'])."</span>";
			} elseif ($tmp['crystal'] < $location['r.crystal']) {
				$location['r.crystal_formatted'] = "<span title=\"Increase since last\"  style=\"color:#99FF99\">".number_format($location['r.crystal'])."</span>";
			} else {
				$location['r.crystal_formatted'] = number_format($location['r.crystal']);
			}
			if ($tmp['deuterium'] > $location['r.deuterium']) {
				$location['r.deuterium_formatted'] = "<span title=\"Decrease since last\"  style=\"color:#FF9999\">".number_format($location['r.deuterium'])."</span>";
			} elseif ($tmp['deuterium'] < $location['r.deuterium']) {
				$location['r.deuterium_formatted'] = "<span title=\"Increase since last\"  style=\"color:#99FF99\">".number_format($location['r.deuterium'])."</span>";
			} else {
				$location['r.deuterium_formatted'] = number_format($location['r.deuterium']);
			}
		} else {
			$location['r.metal_formatted'] = number_format($location['r.metal']);
			$location['r.crystal_formatted'] = number_format($location['r.crystal']);
			$location['r.deuterium_formatted'] = number_format($location['r.deuterium']);
		}
		$date = $ts_now-$location['r.updated'];
		$date = $date/60;
		$location['row_color'] = $location['date_color'] = "inherit";
		if ($date < 60) {
			$location['elapsed'] = floor($date)." min. ago";
			$location['elapsed_color'] = "#00FF00";
			$location['row_color'] = "rgba(0,255,0,0.1)";
		} elseif ($date < 60*24) {
			$location['elapsed'] = floor($date/60)." hrs. ago";
			$location['elapsed_color'] = "#FFFF00";
			$location['row_color'] = "rgba(255,255,0,0.1)";
		} else {
			$location['elapsed'] = floor($date/60/24)." days ago";
			$location['elapsed_color'] = "#FF3333";
			$location['row_color'] = "rgba(255,0,0,0.1)";
		}
		if ($location['l.type'] == 'inactive') {
			$location['row_color'] = "rgba(255,255,255,0.1)";
		}
		
		// Do fleet/defense calculations
		$location['fleet_url'] = $location['defense_url'] = '';
		$location['w_power'] = $location['s_power'] = $location['integrity'] = '?';
		$research = ($location['r.research'] != '')? unserialize($location['r.research']) : array();
		$w_tech = (isset($research['Weapons Technology']))? $research['Weapons Technology'] : 0;
		$s_tech = (isset($research['Shielding Technolog']))? $research['Shielding Technology'] : 0;
		$location['fleet_url'] .= "&dwtech={$w_tech}&dstech={$s_tech}";

		if ($location['r.fleet'] != '') {
			$fleet = ($location['r.fleet'] != '')? unserialize($location['r.fleet']) : array();
			if ($location['w_power'] == '?') $location['w_power'] = 0;
			if ($location['s_power'] == '?') $location['s_power'] = 0;
			if ($location['integrity'] == '?') $location['integrity'] = 0;
			$ship_types = array(
				"Small Cargo" => array('var' => 'dscars', 'int' => 4000, 's' => 10, 'w' => 5),
				"Large Cargo" => array('var' => 'dlcars', 'int' => 30000, 's' => 100, 'w' => 50),
				"Colony Ship" => array('var' => 'dcols', 'int' => 12000, 's' => 25, 'w' => 5),
				"Recycler" => array('var' => 'drecy', 'int' => 16000, 's' => 10, 'w' => 1),
				"Espionage Probe" => array('var' => 'despp', 'int' => 1000, 's' => 0, 'w' => 0),
				"Solar Satellite" => array('var' => 'dsola', 'int' => 2000, 's' => 1, 'w' => 1),
				"Light Fighter" => array('var' => 'dsfig', 'int' => 4000, 's' => 10, 'w' => 50),
				"Heavy Fighter" => array('var' => 'dlfig', 'int' => 10000, 's' => 25, 'w' => 150),
				"Cruiser" => array('var' => 'dcru', 'int' => 27000, 's' => 50, 'w' => 400),
				"Battleship" => array('var' => 'dbats', 'int' => 60000, 's' => 200, 'w' => 1000),
				"Battlecruiser" => array('var' => 'dbetc', 'int' => 70000, 's' => 400, 'w' => 700),
				"Bomber" => array('var' => 'dbomb', 'int' => 75000, 's' => 500, 'w' => 1000),
				"Destroyer" => array('var' => 'ddest', 'int' => 110000, 's' => 500, 'w' => 2000),
				"Deathstar" => array('var' => 'dstar', 'int' => 9000000, 's' => 50000, 'w' => 200000),
			);
			foreach($fleet as $ship => $quantity) {
				if ($ship != '') {
					if (isset($ship_types[$ship])) {
						$location['fleet_url'] .= "&".$ship_types[$ship]['var']."=".$quantity;
						$location['integrity'] += $ship_types[$ship]['int']*$quantity;
						$location['s_power'] += $ship_types[$ship]['s']*(1+$s_tech/10)*$quantity;
						$location['w_power'] += $ship_types[$ship]['w']*(1+$w_tech/10)*$quantity;
					} else {
						echo "Unknown ship type '$ship'... ";
					}
				}
			}
		}
		if ($location['r.defense'] != '') {
			$defense = ($location['r.defense'] != '')? unserialize($location['r.defense']) : array();
			$defense_types = array(
				"Rocket Launcher" => array('var' => 'drock', 'int' => 2000, 's' => 20, 'w' => 80),
				"Light Laser" => array('var' => 'dslas', 'int' => 2000, 's' => 25, 'w' => 100),
				"Heavy Laser" => array('var' => 'dhlas', 'int' => 8000, 's' => 100, 'w' => 250),
				"Gauss Cannon" => array('var' => 'dgaus', 'int' => 35000, 's' => 200, 'w' => 1100),
				"Ion Cannon" => array('var' => 'dionc', 'int' => 8000, 's' => 500, 'w' => 150),
				"Plasma Turret" => array('var' => 'dplas', 'int' => 100000, 's' => 300, 'w' => 3000),
				"Small Shield Dome" => array('var' => 'dsshi', 'int' => 20000, 's' => 2000, 'w' => 1),
				"Large Shield Dome" => array('var' => 'dlshi', 'int' => 100000, 's' => 10000, 'w' => 1),
				"Ballistic Missiles" => array('var' => 'dmiss', 'int' => 0, 's' => 0, 'w' => 0),
			);
			foreach($defense as $name => $quantity) {
				if ($name != '') {
					if (isset($defense_types[$name])) {
						$location['defense_url'] .= '&'.$defense_types[$name]['var'].'='.$quantity;
						$location['integrity'] += $defense_types[$name]['int']*$quantity;
						$location['s_power'] += $defense_types[$name]['s']*(1+$s_tech/10)*$quantity;
						$location['w_power'] += $defense_types[$name]['w']*(1+$w_tech/10)*$quantity;
					} else {
						echo "Unknown defense type '$name'... ";
					}
				}
			}
		}
	}
?>

<div class="block">
<table style="width:100%" cellspacing="0" cellpadding="3">
<thead>
<tr><th><a href="<?=$_SERVER['PHP_SELF']?>?sort=updated">Updated</a></th><th><a href="<?=$_SERVER['PHP_SELF']?>?sort=locname">Name</a></th><th><a href="<?=$_SERVER['PHP_SELF']?>?sort=location">Location</a></th><th><a href="<?=$_SERVER['PHP_SELF']?>?sort=player">Player</a></th><th style="text-align:right"><a href="<?=$_SERVER['PHP_SELF']?>?sort=metal">Metal</a></th><th style="text-align:right"><a href="<?=$_SERVER['PHP_SELF']?>?sort=crystal">Crystal</a></th><th style="text-align:right"><a href="<?=$_SERVER['PHP_SELF']?>?sort=deuterium">Deuterium</a></th><th>Fleet/Defense</th></tr>
</thead>
<tbody>
<?php
foreach($locations as &$location) {
	echo "<tr style=\"background-color:{$location['row_color']}\"><td><span style=\"color:{$location['elapsed_color']}\">{$location['elapsed']}</span> ({$location['x.recordnum']} records total)</td><td><a href=\"{$_SERVER['PHP_SELF']}?l={$location['r.location']}\">{$location['l.name']}</a></td><td>{$location['l.location']}</td><td>{$location['l.player']}</td><td class=\"num\">{$location['r.metal_formatted']}</td><td class=\"num\">{$location['r.crystal_formatted']}</td><td class=\"num\">{$location['r.deuterium_formatted']}</td><td>{$location['w_power']}/{$location['s_power']}/{$location['integrity']} <a href=\"http://www.jimmywest.se/other/calcogame/index.php?page=Attack%20Simulator&m_plu={$location['r.metal']}&c_plu={$location['r.crystal']}&d_plu={$location['r.deuterium']}{$location['fleet_url']}{$location['defense_url']}&e_pos={$location['l.location']}&sim=true\">Atk. sim</a></td></tr>\n";
}
?>
</tbody>
</table>
<p><?php if (!empty($_GET['filter']) && $_GET['filter'] == 'inactive') {
	echo "<a href=\"".$_SERVER['PHP_SELF']."\">Hide inactive planets</a>";
} else {
	echo "<a href=\"".$_SERVER['PHP_SELF']."?filter=inactive\">Show inactive planets</a>";
}?></p>	
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

function most_recent_of($col, $location) {
	global $db;
	$rs = $db->query("SELECT r.{$col} FROM (SELECT location, max(updated) as maxupdated FROM resources WHERE {$col}!='' GROUP BY location) x LEFT JOIN resources r on r.updated=x.maxupdated AND r.location=x.location WHERE r.location={$location} LIMIT 1;");
	if ($rs->numRows() > 0) {
		$rs = $rs->fetch(SQLITE_NUM);
		return $rs[0];
	}
	return false;
}