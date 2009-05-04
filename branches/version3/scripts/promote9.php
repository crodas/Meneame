<?
include('../config.php');
include(mnminclude.'link.php');
include(mnminclude.'user.php');
include_once(mnminclude.'log.php');
include_once(mnminclude.'ban.php');
include_once(mnminclude.'annotation.php');

define('DEBUG', false);

header("Content-Type: text/html");
echo '<html><head><title>promote9.php</title></head><body>';
ob_end_flush();

$min_karma_coef = 0.87;


define(MAX, 1.15);
define (MIN, 1.0);
define (PUB_MIN, 20);
define (PUB_MAX, 75);
define (PUB_PERC, 0.10);




$links_queue = $db->get_var("SELECT SQL_NO_CACHE count(*) from links WHERE link_date > date_sub(now(), interval 24 hour) and link_status in ('published', 'queued')");
$links_queue_all = $db->get_var("SELECT SQL_NO_CACHE count(*) from links WHERE link_date > date_sub(now(), interval 24 hour) and link_votes > 0");


$pub_estimation = intval(max(min($links_queue * PUB_PERC, PUB_MAX), PUB_MIN));
$interval = intval(86400 / $pub_estimation);

$now = time();
$output .= "<p><b>BEGIN</b>: ".get_date_time($now)."<br/>\n";

$from_time = "date_sub(now(), interval 5 day)";
#$from_where = "FROM votes, links WHERE  


$last_published = $db->get_var("SELECT SQL_NO_CACHE UNIX_TIMESTAMP(max(link_date)) from links WHERE link_status='published'");
if (!$last_published) $last_published = $now - 24*3600*30;
$links_published = (int) $db->get_var("select SQL_NO_CACHE count(*) from links where link_status = 'published' and link_date > date_sub(now(), interval 24 hour)");
$links_published_projection = 4 * (int) $db->get_var("select SQL_NO_CACHE count(*) from links where link_status = 'published' and link_date > date_sub(now(), interval 6 hour)");

$diff = $now - $last_published;
// If published and estimation are lower than projection then
// fasten decay
if ($diff < $interval && ($links_published_projection < $pub_estimation * 0.9 && $links_published < $pub_estimation * 0.9 )) {
	$diff = max($diff * 2, $interval);
}

$decay = min(MAX, MAX - ($diff/$interval)*(MAX-MIN) );
/*
if ($decay > MIN && ($links_published_projection < $pub_estimation * 0.9 || $links_published < $pub_estimation * 0.9)) {
	$decay = MIN;
}
*/
$decay = max($min_karma_coef, $decay);

if ($diff > $interval * 2) {
	$must_publish = true;
	$output .= "Delayed! <br/>";
}
$output .= "Last published at: " . get_date_time($last_published) ."<br/>\n";
$output .= "24hs queue: $links_queue/$links_queue_all, Published: $links_published -> $links_published_projection Published goal: $pub_estimation, Interval: $interval secs, difference: ". intval($now - $last_published)." secs, Decay: $decay<br/>\n";

$continue = true;
$published=0;

$past_karma_long = intval($db->get_var("SELECT SQL_NO_CACHE avg(link_karma) from links WHERE link_date >= date_sub(now(), interval 7 day) and link_status='published'"));
$past_karma_short = intval($db->get_var("SELECT SQL_NO_CACHE avg(link_karma) from links WHERE link_date >= date_sub(now(), interval 12 hour) and link_status='published'"));

$past_karma = 0.5 * max(40, $past_karma_long) + 0.5 * max($past_karma_long*0.8, $past_karma_short);
$min_past_karma = (int) ($past_karma * $min_karma_coef);
$last_resort_karma = (int) $past_karma * 0.75;


//////////////
$min_karma = round(max($past_karma * $decay, 20));

if ($decay >= 1) $max_to_publish = 3;
else $max_to_publish = 1;

$min_votes = 5;
/////////////

$limit_karma = round(min($past_karma,$min_karma) * 0.65);
$bonus_karma = round(min($past_karma,$min_karma) * 0.40);


/// Coeficients to balance metacategories
$days = 2;
$total_published = (int) $db->get_var("select SQL_NO_CACHE count(*) from links where link_status = 'published' and link_date > date_sub(now(), interval $days day)");
$db_metas = $db->get_results("select category_id, category_name, category_calculated_coef from categories where category_parent = 0 and category_id in (select category_parent from categories where category_parent > 0)");
foreach ($db_metas as $dbmeta) {
	$meta = $dbmeta->category_id;
	$meta_previous_coef[$meta] = $dbmeta->category_calculated_coef;
	$meta_names[$meta] = $dbmeta->category_name;
	$x = (int) $db->get_var("select SQL_NO_CACHE count(*) from links, categories where link_status = 'published' and link_date > date_sub(now(), interval $days day) and link_category = category_id and category_parent = $meta");
	$y = (int) $db->get_var("select SQL_NO_CACHE count(*) from links, categories where link_status in ('published', 'queued') and link_date > date_sub(now(), interval $days day) and link_category = category_id and category_parent = $meta");
	$meta_coef[$meta] = $x/$y;
	$meta_coef[$meta] = 0.7 * $meta_coef[$meta] + 0.3 * $x / $total_published / count($db_metas) ;
	$meta_avg += $meta_coef[$meta] / count($db_metas);
	$output .= "$days days stats for <b>$meta_names[$meta]</b> (queued/published/total): $y/$x/$total_published -> $meta_coef[$meta]<br/>";
	//echo "$meta: $meta_coef[$meta] - $x / $y<br>";
}
foreach ($meta_coef as $m => $v) {
	$meta_coef[$m] = max(min($meta_avg/$v, 1.4), 0.7);
	if ($meta_previous_coef[$m]  > 0.6 && $meta_previous_coef[$m]  < 1.5) {
		//echo "Previous: $meta_previous_coef[$m], current: $meta_coef[$m] <br>";
		$meta_coef[$m] = 0.05 * $meta_coef[$m] + 0.95 * $meta_previous_coef[$m] ;
	}
	$output .= "Karma coefficient for <b>$meta_names[$m]</b>: $meta_coef[$m]<br/>";
	// Store current coef in DB
	if (! DEBUG) {
		$db->query("update categories set category_calculated_coef = $meta_coef[$m] where (category_id = $m || category_parent = $m)");
	}
	$log = new Annotation("metas-coef");
	$log->text = serialize($meta_coef);
	$log->store();
}


// Karma average:  It's used for each link to check the balance of users' votes

global $users_karma_avg;
$users_karma_avg = (float) $db->get_var("select SQL_NO_CACHE avg(link_votes_avg) from links where link_status = 'published' and link_date > date_sub(now(), interval 72 hour)");

$output .= "Karma average for each link: $users_karma_avg, Past karma. Long term: $past_karma_long, Short term: $past_karma_short, Average: <b>$past_karma</b><br/>\n";
$output .= "<b>Current MIN karma: $min_karma</b>, absolute min karma: $min_past_karma, analizing from $limit_karma<br/>\n";
$output .= "</p>\n";




$where = "link_date > $from_time AND link_status = 'queued' AND link_votes>=$min_votes  AND (link_karma > $limit_karma or (link_date > date_sub(now(), interval 2 hour) and link_karma > $bonus_karma)) and user_id = link_author and category_id = link_category";
$sort = "ORDER BY link_karma DESC, link_votes DESC";

$links = $db->get_results("SELECT SQL_NO_CACHE link_id, link_karma as karma, category_parent as parent from links, users, categories where $where $sort LIMIT 30");
$rows = $db->num_rows;
if (!$rows) {
	$output .= "There are no articles<br/>\n";
	$output .= "--------------------------<br/>\n";
	die;
}
	
$max_karma_found = 0;
$best_link = 0;
$best_karma = 0;
$output .= "<table>\n";	
if ($links) {
	$output .= "<tr class='thead'><th>votes</th><th>anon</th><th>neg.</th><th>coef</th><th>karma</th><th>meta</th><th>title</th><th>changes</th></tr>\n";
	$i=0;
	foreach($links as $dblink) {
		$link = new Link;
		$link->id=$dblink->link_id;
		$link->read();
		$user = new User;
		$user->id = $link->author;
		$user->read();
		$karma_pos_user = 0;
		$karma_neg_user = 0;
		$karma_pos_ano = 0;


		$affinity = check_affinity($link->author, $past_karma*0.3);

		$previous_karma = $link->karma;
		// Calculate the real karma for the link
		//$db->query("LOCK TABLES votes, users READ, links WRITE, logs WRITE");
		$link->calculate_karma();

		if ($link->coef > 1) {
			if ($decay > 1) 
				$karma_threshold = $past_karma;
			else
				$karma_threshold = $min_karma;
		} else {
			// Otherwise use normal decayed min_karma
			$karma_threshold = $min_karma;
		}


		//$karma_new = $link->karma * $meta_coef[$dblink->parent];
		$karma_new = $link->karma;
		$link->message = '';
		$changes = 0;
$link->message .= "<br>Meta: $link->meta_id coef: ".$meta_coef[$link->meta_id]." Init values: previous: $previous_karma calculated: $link->karma new: $karma_new<br>\n";
		if (DEBUG ) $link->message .= "<br>Meta: $link->meta_id coef: ".$meta_coef[$link->meta_id]." Init values: previous: $previous_karma calculated: $link->karma new: $karma_new<br>\n";

		// Verify last published from the same site
		$hours = 8;
		$min_pub_coef = 0.8;
		$last_site_published = (int) $db->get_var("select SQL_NO_CACHE UNIX_TIMESTAMP(max(link_date)) from links where link_blog = $link->blog and link_status = 'published' and link_date > date_sub(now(), interval $hours hour)");
		if ($last_site_published > 0) {
			$pub_coef = $min_pub_coef  + ( 1- $min_pub_coef) * (time() - $last_site_published)/(3600*$hours);
			$karma_new *= $pub_coef;
			$link->message .= '<br/> Last published: '. intval((time() - $last_site_published)/3600) . ' hours ago.';
		}

		
		if(check_ban($link->url, 'hostname', false, true)) {
			// Check if the  domain is banned
			$karma_new *= 0.5;
			$link->message .= '<br/>Domain banned. ';
		} elseif ($user->level == 'disabled' ) {
			// Check if the user is banned disabled
			if (preg_match('/^_+[0-9]+_+$/', $user->username)) {
				$link->message .= "<br/>$user->username disabled herself, penalized.";
			} else {
				$link->message .= "<br/>$user->username disabled, probably due to abuses, penalized.";
			}
			$karma_new *= 0.5;
		} elseif (check_ban($link->url, 'punished_hostname', false, true)) {
			// Check domain and user punishments
			$karma_new *= 0.75;
			$link->message .= '<br/>' . $globals['ban_message'];
		} elseif ($meta_coef[$dblink->parent] < 1.02 && ($link->content_type == 'image')) {
			// check if it's "media" and the metacategory coefficient is low
			$karma_new *= 0.9;
			$link->message .= '<br/>Image/Video '.$meta_coef[$dblink->parent];
		}

		//echo "pos: $karma_pos_user_high, $karma_pos_user_low -> $karma_pos_user -> $karma_new\n";

		// check differences, if > 4 store it
		if (abs($previous_karma - $karma_new) > 4) {
			$link->message = sprintf ("<br/>updated karma: %6d (%d, %d, %d) -> %-6d\n", $link->karma, $link->votes, $link->anonymous, $link->negatives, round($karma_new) ) . $link->message;
			if ($link->karma > $karma_new) $changes = 1; // to show a "decrease" later	
			else $changes = 2; // increase
			$link->karma = round($karma_new);
			if (! DEBUG) {
				$link->store_basic();
				$link->message .= "Storing: previous: $previous_karma new: $link->karma<br>\n";
			} else {
				$link->message .= "To store: previous: $previous_karma new: $link->karma<br>\n";
			}
		}
		//$db->query("UNLOCK TABLES");


		if (! DEBUG && $link->thumb_status == 'unknown') $link->get_thumb();

		if ($link->votes >= $min_votes && $karma_new >= $karma_threshold && $published < $max_to_publish) {
			$published++;
			$link->karma = round($karma_new);
			publish($link);
			$changes = 3; // to show a "published" later	
		} else {
			if (( $must_publish || $link->karma > $min_past_karma) 
						&& $link->karma > $limit_karma && $link->karma > $last_resort_karma &&
						$link->votes > $link->negatives*20) {
				$last_resort_id = $link->id;
				$last_resort_karma = $link->karma;
			}
		}
		print_row($link, $changes);
		usleep(10000);
		$i++;
	}
	if (! DEBUG && $published == 0 && $links_published_projection < $pub_estimation * 0.9 && $must_publish && $last_resort_id  > 0) {
		// Publish last resort
		$link = new Link;
		$link->id = $last_resort_id;
		if ($link->read()) {
			$link->message = "Last resort: selected with the best karma";
			print_row($link, 3);
			publish($link);
		}
	}
	//////////
}
$output .= "</table>\n";

echo $output;
echo "</body></html>\n";
if (! DEBUG) {
	$annotation = new Annotation('promote');
	$annotation->text = $output;
	$annotation->store();
}

function print_row($link, $changes, $log = '') {
	global $globals, $output;
	static $row = 0;

	$mod = $row%2;

	$output .= "<tr><td class='tnumber$mod'>".$link->votes."</td><td class='tnumber$mod'>".$link->anonymous."</td><td class='tnumber$mod'>".$link->negatives."</td><td class='tnumber$mod'>" . sprintf("%0.2f", $link->coef). "</td><td class='tnumber$mod'>".intval($link->karma)."</td>";
	$output .= "<td class='tdata$mod'>$link->meta_name</td>\n";
	$output .= "<td class='tdata$mod'><a href='".$link->get_relative_permalink()."'>$link->title</a>\n";
	if (!empty($link->message)) {
		$output .= "$link->message";
	}
	$link->message = '';
	$output .= "</td>\n";
	$output .= "<td class='tnumber$mod'>";
	switch ($changes) {
		case 1:
			$output .= '<img src="'.$globals['base_url'].'img/common/sneak-problem01.png" width="21" height="17" alt="'. _('descenso') .'"/>';
			break;
		case 2:
			$output .= '<img src="'.$globals['base_url'].'img/common/sneak-vote01.png" width="21" height="17" alt="'. _('ascenso') .'"/>';
			break;
		case 3:
			$output .= '<img src="'.$globals['base_url'].'img/common/sneak-published01.png" width="21" height="17" alt="'. _('publicada') .'"/>';
			break;
	}
	$output .= "</td>";
	$output .= "</tr>\n";
	flush();
	$row++;

}


function publish($link) {
	global $globals, $db;
	global $users_karma_avg;

	//return;
	if (DEBUG) return;

	// Calculate votes average
	// it's used to calculate and check future averages
	$votes_avg = (float) $db->get_var("select SQL_NO_CACHE avg(vote_value) from votes, users where vote_type='links' AND vote_link_id=$link->id and vote_user_id > 0 and vote_value > 0 and vote_user_id = user_id and user_level !='disabled'");
	if ($votes_avg < $users_karma_avg) $link->votes_avg = max($votes_avg, $users_karma_avg*0.97);
	else $link->votes_avg = $votes_avg;

	$link->status = 'published';
	$link->date = $link->published_date=time();
	$link->store_basic();

	// Increase user's karma
	$user = new User;
	$user->id = $link->author;
	if ($user->read()) {
		$user->karma = min(20, $user->karma + 1);
		$user->store();
		$annotation = new Annotation("karma-$user->id");
		$annotation->append(_('Noticia publicada').": +1, karma: $user->karma\n");
	}

	// Add the publish event/log
	log_insert('link_publish', $link->id, $link->author);

	$short_url = fon_gs($link->get_permalink());
	if ($globals['twitter_user'] && $globals['twitter_password']) {
		twitter_post($link, $short_url); 
	}
	if ($globals['jaiku_user'] && $globals['jaiku_key']) {
		jaiku_post($link, $short_url); 
	}
	// Recheck for images, some sites add images after the article has been published
	if ($link->thumb_status != 'local' && $link->thumb_status != 'deleted') $link->get_thumb();

}
function twitter_post($link, $short_url) {
	global $globals;

	$t_status = urlencode($link->title. ' ' . $short_url);
	syslog(LOG_NOTICE, "Meneame: twitter updater called, id=$link->id");
	$t_url = "http://twitter.com/statuses/update.xml";

	if (!function_exists('curl_init')) {
		syslog(LOG_NOTICE, "Meneame: curl is not installed");
		return;
	}
	$session = curl_init();
	curl_setopt($session, CURLOPT_URL, $t_url);
	curl_setopt($session, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
	curl_setopt($session, CURLOPT_HEADER, false);
	curl_setopt($session, CURLOPT_USERAGENT, "meneame.net");
	curl_setopt($session, CURLOPT_CONNECTTIMEOUT, 15);
	curl_setopt($session, CURLOPT_USERPWD, $globals['twitter_user'] . ":" . $globals['twitter_password']);
	curl_setopt($session, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($session, CURLOPT_POST, 1);
	curl_setopt($session, CURLOPT_POSTFIELDS,"status=" . $t_status);
	$result = curl_exec($session);
	curl_close($session);
}


function jaiku_post($link, $short_url) {
	global $globals;

	syslog(LOG_NOTICE, "Meneame: jaiku updater called, id=$link->id");
	$url = "http://api.jaiku.com/json";

	if (!function_exists('curl_init')) {
		syslog(LOG_NOTICE, "Meneame: curl is not installed");
		return;
	}


	$postdata =  "method=presence.send";
	$postdata .= "&user=" . urlencode($globals['jaiku_user']);
	$postdata .= "&personal_key=" . $globals['jaiku_key'];
	$postdata .= "&icon=337"; // Event
	$postdata .= "&message=" . urlencode(html_entity_decode($link->title). ' ' . $short_url);

	$session = curl_init();
	curl_setopt($session, CURLOPT_URL, $url);
	curl_setopt($session, CURLOPT_HEADER, false);
	curl_setopt($session, CURLOPT_USERAGENT, "meneame.net");
	curl_setopt($session, CURLOPT_CONNECTTIMEOUT, 15);
	curl_setopt($session, CURLOPT_TIMEOUT, 20);
	curl_setopt ($session, CURLOPT_FOLLOWLOCATION,1); 
	curl_setopt($session, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($session, CURLOPT_POST, 1);
	curl_setopt($session, CURLOPT_POSTFIELDS,$postdata);
	$result = curl_exec($session);
	curl_close($session);
}

function fon_gs($url) {
	if (!function_exists('curl_init')) {
		syslog(LOG_NOTICE, "Meneame: curl is not installed");
		return $url;
	}
	$gs_url = 'http://fon.gs/create.php?url='.urlencode($url);
	$session = curl_init();
	curl_setopt($session, CURLOPT_URL, $gs_url);
	curl_setopt($session, CURLOPT_USERAGENT, "meneame.net");
	curl_setopt($session, CURLOPT_CONNECTTIMEOUT, 10);
	curl_setopt($session, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($session, CURLOPT_TIMEOUT, 20);
	$result = curl_exec($session);
	curl_close($session);
	if (preg_match('/^OK/', $result)) {
		$array = explode(' ', $result);
		return $array[1];
	} else return $url;
}

function check_affinity($uid, $min_karma) {
	global $globals, $db;

	$affinity = array();
	$log = new Annotation("affinity-$uid");
	if ($log->read() && $log->time > time() - 3600*4) {
		return unserialize($log->text);
	}
	$db->query("delete from annotations where annotation_key like 'affinity-%' and annotation_time < date_sub(now(), interval 15 day)");
	$link_ids = $db->get_col("SELECT SQL_NO_CACHE link_id FROM links WHERE link_date > date_sub(now(), interval 30 day) and link_author = $uid and link_karma > $min_karma");
	$nlinks = count($link_ids);
	if ($nlinks < 5) {
		$log->store();
		return false;
	}

	$links = implode(',', $link_ids);
	$votes = $db->get_results("select SQL_NO_CACHE vote_user_id as id, sum(vote_value/abs(vote_value)) as count from votes where vote_link_id in ($links) and vote_type='links' group by vote_user_id");
	if ($votes) {
		foreach ($votes as $vote) {
			if ($vote->id > 0 && $vote->id != $uid && abs($vote->count) > max(1, $nlinks/10) ) {
				$c = $vote->count/$nlinks * 0.70;
				if ($vote->count > 0) {
					$affinity[$vote->id] = round((1 - $c)*100);  // store as int (percent) to save space,
				} else {
					$affinity[$vote->id] = round((-1 - $c)*100);  // store as int (percent) to save space,
				}
			
			}
		}
		$log->text = serialize($affinity);
	} else {
		$affinity = false;
	}
	$log->store();
	return $affinity;

}
?>