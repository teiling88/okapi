<?php

namespace okapi\services\caches\geocaches;

use Exception;
use okapi\Okapi;
use okapi\Db;
use okapi\Settings;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\OkapiInternalRequest;
use okapi\OkapiServiceRunner;
use okapi\services\caches\search\SearchAssistant;

class WebService
{
	public static function options()
	{
		return array(
			'min_auth_level' => 1
		);
	}
	
	public static $valid_field_names = array('code', 'name', 'names', 'location', 'type',
		'status', 'url', 'owner', 'founds', 'notfounds', 'size', 'difficulty', 'terrain',
		'rating', 'rating_votes', 'recommendations', 'req_passwd', 'description', 'descriptions', 'hint',
		'hints', 'images', 'attrnames', 'latest_logs', 'trackables_count', 'trackables', 'last_found',
		'last_modified', 'date_created', 'date_hidden', 'internal_id');
	
	public static function call(OkapiRequest $request)
	{
		$cache_codes = $request->get_parameter('cache_codes');
		if (!$cache_codes) throw new ParamMissing('cache_codes');
		$cache_codes = explode("|", $cache_codes);
		if (count($cache_codes) > 500)
			throw new InvalidParam('cache_codes', "Maximum allowed number of referenced ".
				"caches is 500. You provided ".count($cache_codes)." cache codes.");
		if (count($cache_codes) != count(array_unique($cache_codes)))
			throw new InvalidParam('cache_codes', "Duplicate codes detected (make sure each cache is referenced only once).");
		$langpref = $request->get_parameter('langpref');
		if (!$langpref) $langpref = "en";
		$langpref = explode("|", $langpref);
		$fields = $request->get_parameter('fields');
		if (!$fields) $fields = "code|name|location|type|status";
		$fields = explode("|", $fields);
		foreach ($fields as $field)
			if (!in_array($field, self::$valid_field_names))
				throw new InvalidParam('fields', "'$field' is not a valid field code.");
		$lpc = $request->get_parameter('lpc');
		if ($lpc === null) $lpc = 10;
		if ($lpc == 'all')
			$lpc = null;
		else
		{
			if (!is_numeric($lpc))
				throw new InvalidParam('lpc', "Invalid number: '$lpc'");
			$lpc = intval($lpc);
			if ($lpc < 0)
				throw new InvalidParam('lpc', "Must be a positive value.");
		}

		if (Settings::get('OC_BRANCH') == 'oc.de')
		{
			# DE branch:
			# - Caches do not have ratings.
			# - Total numbers of found and notfounds are kept in the "stat_caches" table.
			
			$rs = Db::query("
				select
					c.cache_id, c.name, c.longitude, c.latitude, c.last_modified,
					c.date_created, c.type, c.status, c.date_hidden, c.size, c.difficulty,
					c.terrain, c.wp_oc, c.logpw, u.uuid as user_uuid, u.username, u.user_id,
					
					ifnull(sc.toprating, 0) as topratings,
					ifnull(sc.found, 0) as founds,
					ifnull(sc.notfound, 0) as notfounds,
					sc.last_found,
					0 as votes, 0 as score
					-- SEE ALSO OC.PL BRANCH BELOW
				from
					caches c
					inner join user u on c.user_id = u.user_id
					left join stat_caches as sc on c.cache_id = sc.cache_id
				where
					binary wp_oc in ('".implode("','", array_map('mysql_real_escape_string', $cache_codes))."')
			");
		}
		elseif (Settings::get('OC_BRANCH') == 'oc.pl')
		{
			# PL branch:
			# - Caches have ratings.
			# - Total numbers of found and notfounds are kept in the "caches" table.
			
			$rs = Db::query("
				select
					c.cache_id, c.name, c.longitude, c.latitude, c.last_modified,
					c.date_created, c.type, c.status, c.date_hidden, c.size, c.difficulty,
					c.terrain, c.wp_oc, c.logpw, u.uuid as user_uuid, u.username, u.user_id,
					
					c.topratings,
					c.founds,
					c.notfounds,
					c.last_found,
					c.votes, c.score
					-- SEE ALSO OC.DE BRANCH ABOVE
				from
					caches c,
					user u
				where
					binary wp_oc in ('".implode("','", array_map('mysql_real_escape_string', $cache_codes))."')
					and c.user_id = u.user_id
			");
		}

		$results = array();
		$cacheid2wptcode = array();
		while ($row = mysql_fetch_assoc($rs))
		{
			$entry = array();
			$cacheid2wptcode[$row['cache_id']] = $row['wp_oc'];
			foreach ($fields as $field)
			{
				switch ($field)
				{
					case 'code': $entry['code'] = $row['wp_oc']; break;
					case 'name': $entry['name'] = $row['name']; break;
					case 'names': $entry['names'] = array(Settings::get('SITELANG') => $row['name']); break; // for the future
					case 'location': $entry['location'] = round($row['latitude'], 6)."|".round($row['longitude'], 6); break;
					case 'type': $entry['type'] = Okapi::cache_type_id2name($row['type']); break;
					case 'status': $entry['status'] = Okapi::cache_status_id2name($row['status']); break;
					case 'url': $entry['url'] = $GLOBALS['absolute_server_URI']."viewcache.php?cacheid=".$row['cache_id']; break;
					case 'owner':
						$entry['owner'] = array(
							'uuid' => $row['user_uuid'],
							'username' => $row['username'],
							'profile_url' => $GLOBALS['absolute_server_URI']."viewprofile.php?userid=".$row['user_id']
						);
						break;
					case 'founds': $entry['founds'] = $row['founds'] + 0; break;
					case 'notfounds': $entry['notfounds'] = $row['notfounds'] + 0; break;
					case 'size': $entry['size'] = ($row['size'] < 7) ? $row['size'] - 1 : null; break;
					case 'difficulty': $entry['difficulty'] = round($row['difficulty'] / 2.0, 1); break;
					case 'terrain': $entry['terrain'] = round($row['terrain'] / 2.0, 1); break;
					case 'rating':
						if ($row['votes'] <= 3) $entry['rating'] = null;
						elseif ($row['score'] >= 2.2) $entry['rating'] = 5;
						elseif ($row['score'] >= 1.4) $entry['rating'] = 4;
						elseif ($row['score'] >= 0.1) $entry['rating'] = 3;
						elseif ($row['score'] >= -1.0) $entry['rating'] = 2;
						else $entry['rating'] = 1;
						break;
					case 'rating_votes': $entry['rating_votes'] = $row['votes'] + 0; break;
					case 'recommendations': $entry['recommendations'] = $row['topratings'] + 0; break;
					case 'req_passwd': $entry['req_passwd'] = $row['logpw'] ? true : false; break;
					case 'description': /* handled separately */ break;
					case 'descriptions': /* handled separately */ break;
					case 'hint': /* handled separately */ break;
					case 'hints': /* handled separately */ break;
					case 'images': /* handled separately */ break;
					case 'attrnames': /* handled separately */ break;
					case 'latest_logs': /* handled separately */ break;
					case 'trackables_count': /* handled separately */ break;
					case 'trackables': /* handled separately */ break;
					case 'last_found': $entry['last_found'] = $row['last_found'] ? date('c', strtotime($row['last_found'])) : null; break;
					case 'last_modified': $entry['last_modified'] = date('c', strtotime($row['last_modified'])); break;
					case 'date_created': $entry['date_created'] = date('c', strtotime($row['date_created'])); break;
					case 'date_hidden': $entry['date_hidden'] = date('c', strtotime($row['date_hidden'])); break;
					case 'internal_id': $entry['internal_id'] = $row['cache_id']; break;
					default: throw new Exception("Missing field case: ".$field);
				}
			}
			$results[$row['wp_oc']] = $entry;
		}
		mysql_free_result($rs);
		if (count($cache_codes) != count($results))
		{
			throw new InvalidParam('cache_codes', "Some of the referenced caches could not be found.");
		}
		
		# Descriptions and hints.
		
		if (in_array('description', $fields) || in_array('descriptions', $fields)
			|| in_array('hint', $fields) || in_array('hints', $fields))
		{
			# At first, we will fill all those 4 fields, even if user requested just one
			# of them. We will chop off the remaining three at the end.
			
			foreach ($results as &$result_ref)
				$result_ref['descriptions'] = array();
			foreach ($results as &$result_ref)
				$result_ref['hints'] = array();
			
			# Get cache descriptions and hints.
			
			$rs = Db::query("
				select cache_id, language, `desc`, hint
				from cache_desc
				where cache_id in ('".implode("','", array_map('mysql_real_escape_string', array_keys($cacheid2wptcode)))."')
			");
			while ($row = mysql_fetch_assoc($rs))
			{
				$cache_code = $cacheid2wptcode[$row['cache_id']];
				// strtolower - ISO 639-1 codes are lowercase
				if ($row['desc'])
					$results[$cache_code]['descriptions'][strtolower($row['language'])] = $row['desc'].
						"\n".self::get_cache_attribution_note($row['cache_id'], strtolower($row['language']));
				if ($row['hint'])
					$results[$cache_code]['hints'][strtolower($row['language'])] = $row['hint'];
			}
			foreach ($results as &$result_ref)
			{
				$result_ref['description'] = Okapi::pick_best_language($result_ref['descriptions'], $langpref);
				$result_ref['hint'] = Okapi::pick_best_language($result_ref['hints'], $langpref);
			}
			
			# Remove unwanted fields.
			
			foreach (array('description', 'descriptions', 'hint', 'hints') as $field)
				if (!in_array($field, $fields))
					foreach ($results as &$result_ref)
						unset($result_ref[$field]);
		}
		
		# Images.
		
		if (in_array('images', $fields))
		{
			foreach ($results as &$result_ref)
				$result_ref['images'] = array();
			$rs = Db::query("
				select object_id, uuid, url, thumb_url, title, spoiler
				from pictures
				where
					object_id in ('".implode("','", array_map('mysql_real_escape_string', array_keys($cacheid2wptcode)))."')
					and display = 1 and object_type = 2
				order by object_id, last_modified
			");
			$prev_cache_code = null;
			while ($row = mysql_fetch_assoc($rs))
			{
				$cache_code = $cacheid2wptcode[$row['object_id']];
				if ($cache_code != $prev_cache_code)
				{
					# Group images together. Images must have unique captions within one cache.
					self::reset_unique_captions();
					$prev_cache_code = $cache_code;
				}
				$results[$cache_code]['images'][] = array(
					'uuid' => $row['uuid'],
					'url' => $row['url'],
					'thumb_url' => $row['thumb_url'] ? $row['thumb_url'] : null,
					'caption' => $row['title'],
					'unique_caption' => self::get_unique_caption($row['title']),
					'is_spoiler' => ($row['spoiler'] ? true : false),
				);
			}
		}
		
		# Attrnames
		
		if (in_array('attrnames', $fields))
		{
			foreach ($results as &$result_ref)
				$result_ref['attrnames'] = array();
			$rs = Db::query("select id, language, text_long from cache_attrib order by id");
			$dict = array();
			while ($row = mysql_fetch_assoc($rs))
				$dict[$row['id']][strtolower($row['language'])] = $row['text_long'];
			$rs = Db::query("
				select cache_id, attrib_id
				from caches_attributes
				where cache_id in ('".implode("','", array_map('mysql_real_escape_string', array_keys($cacheid2wptcode)))."')
			");
			while ($row = mysql_fetch_assoc($rs))
			{
				$cache_code = $cacheid2wptcode[$row['cache_id']];
				if (isset($dict[$row['attrib_id']]))
				{
					# The "isset" condition was added because there were some attrib_ids in caches_attributes
					# which WERE NOT in cache_attrib. http://code.google.com/p/opencaching-api/issues/detail?id=77
					$results[$cache_code]['attrnames'][] = Okapi::pick_best_language($dict[$row['attrib_id']], $langpref);
				}
			}
		}
		
		# Latest log entries.
		
		if (in_array('latest_logs', $fields))
		{
			foreach ($results as &$result_ref)
				$result_ref['latest_logs'] = array();
			
			# Get log IDs and dates. Sort in groups. Filter out latest ones. This is the fastest
			# technique I could think of...
			
			$rs = Db::query("
				select cache_id, id
				from cache_logs
				where
					cache_id in ('".implode("','", array_map('mysql_real_escape_string', array_keys($cacheid2wptcode)))."')
					and deleted = 0
			");
			$logids = array();
			if ($lpc !== null)
			{
				# User wants some of the latest logs.
				$tmp = array();
				while ($row = mysql_fetch_assoc($rs))
					$tmp[$row['cache_id']][] = $row['id'];
				foreach ($tmp as $cache_key => &$logids_ref)
				{
					rsort($logids_ref);
					$logids = array_merge($logids, array_slice($logids_ref, 0, $lpc));
				}
			}
			else
			{
				# User wants ALL logs.
				while ($row = mysql_fetch_assoc($rs))
					$logids[] = $row['id'];
			}
			
			# Now retrieve text and join.
			
			$rs = Db::query("
				select cl.cache_id, cl.id, cl.uuid, cl.type, unix_timestamp(cl.date) as date, cl.text,
					u.uuid as user_uuid, u.username, u.user_id
				from cache_logs cl, user u
				where
					cl.id in ('".implode("','", array_map('mysql_real_escape_string', $logids))."')
					and cl.deleted = 0
					and cl.user_id = u.user_id
				order by cl.cache_id, cl.id desc
			");
			$cachelogs = array();
			while ($row = mysql_fetch_assoc($rs))
			{
				$results[$cacheid2wptcode[$row['cache_id']]]['latest_logs'][] = array(
					'uuid' => $row['uuid'],
					'date' => date('c', $row['date']),
					'user' => array(
						'uuid' => $row['user_uuid'],
						'username' => $row['username'],
						'profile_url' => $GLOBALS['absolute_server_URI']."viewprofile.php?userid=".$row['user_id'],
					),
					'type' => Okapi::logtypeid2name($row['type']),
					'comment' => $row['text']
				);
			}
		}
		
		
		if (in_array('trackables', $fields))
		{
			# Currently we support Geokrety only. But this interface should remain
			# compatible. In future, other trackables might be returned the same way.
			
			$rs = Db::query("
				select
					gkiw.wp as cache_code,
					gki.id as gk_id,
					gki.name
				from
					gk_item_waypoint gkiw,
					gk_item gki
				where
					gkiw.id = gki.id
					and gkiw.wp in ('".implode("','", array_map('mysql_real_escape_string', $cache_codes))."')
			");
			$trs = array();
			while ($row = mysql_fetch_assoc($rs))
				$trs[$row['cache_code']][] = $row;
			foreach ($results as $cache_code => &$result_ref)
			{
				$result_ref['trackables'] = array();
				if (!isset($trs[$cache_code]))
					continue;
				foreach ($trs[$cache_code] as $t)
				{
					$result_ref['trackables'][] = array(
						'code' => 'GK'.str_pad(strtoupper(dechex($t['gk_id'])), 4, "0", STR_PAD_LEFT),
						'name' => $t['name'],
						'url' => 'http://geokrety.org/konkret.php?id='.$t['gk_id']
					);
				}
			}
			unset($trs);
		}
		if (in_array('trackables_count', $fields))
		{
			if (in_array('trackables', $fields))
			{
				# We already got all trackables data, no need to query database again.
				foreach ($results as $cache_code => &$result_ref)
					$result_ref['trackables_count'] = count($result_ref['trackables']);
			}
			else
			{
				$rs = Db::query("
					select wp as cache_code, count(*) as count
					from gk_item_waypoint
					where wp in ('".implode("','", array_map('mysql_real_escape_string', $cache_codes))."')
					group by wp
				");
				$tr_counts = array();
				while ($row = mysql_fetch_assoc($rs))
					$tr_counts[$row['cache_code']] = $row['count'];
				foreach ($results as $cache_code => &$result_ref)
				{
					if (isset($tr_counts[$cache_code]))
						$result_ref['trackables_count'] = $tr_counts[$cache_code] + 0;
					else
						$result_ref['trackables_count'] = 0;
				}
				unset($tr_counts);
			}
		}
		
		# Check which cache codes were not found and mark them with null.
		foreach ($cache_codes as $cache_code)
			if (!isset($results[$cache_code]))
				$results[$cache_code] = null;
		
		# Order the results in the same order as the input codes were given.
		# This might come in handy for languages which support ordered dictionaries
		# (especially with conjunction with the search_and_retrieve method).
		# See issue#97. PHP dictionaries (assoc arrays) are ordered structures,
		# so we just have to rewrite it (sequentially).
		
		$ordered_results = array();
		foreach ($cache_codes as $cache_code)
			$ordered_results[$cache_code] = $results[$cache_code];
		
		return Okapi::formatted_response($request, $ordered_results);
	}
	
	/**
	 * Create unique caption, safe to be used as a file name for images
	 * uploaded into Garmin's GPS devices. Use reset_unique_captions to reset
	 * unique counter.
	 */
	private static function get_unique_caption($caption)
	{
		# Garmins keep hanging on long file names. We don't have any specification from
		# Garmin and cannot determine WHY. That's why we won't use captions until we
		# know more.
		
		$caption = self::$caption_no."";
		self::$caption_no++;
		return $caption;
		
		/* This code is harmful for Garmins!
		$caption = preg_replace('#[^\\pL\d ]+#u', '-', $caption);
		$caption = trim($caption, '-');
		if (function_exists('iconv'))
		{
			$new = iconv("utf-8", "ASCII//TRANSLIT", $caption);
			if (!$new)
				$new = iconv("UTF-8", "ASCII//IGNORE", $caption);
		} else {
			$new = $caption;
		}
		$new = str_replace(array('/', '\\', '?', '%', '*', ':', '|', '"', '<', '>', '.'), '', $new);
		$new = trim($new);
		if ($new == "")
			$new = "(no caption)";
		if (strlen($new) > 240)
			$new = substr($new, 0, 240);
		$new = self::$caption_no." - ".$new;
		self::$caption_no++;
		return $new;*/
	}
	private static $caption_no = 1;
	private static function reset_unique_captions()
	{
		self::$caption_no = 1;
	}
	
	public static function get_cache_attribution_note($cache_id, $lang)
	{
		$site_url = $GLOBALS['absolute_server_URI'];
		$site_name = Okapi::get_normalized_site_name();
		$cache_url = $site_url."viewcache.php?cacheid=$cache_id";
		
		# This list if to be extended (opencaching.de, etc.).
		
		switch ($lang)
		{
			case 'pl':
				return "<p>Opis <a href='$cache_url'>skrzynki</a> pochodzi z serwisu <a href='$site_url'>$site_name</a>.</p>";
				break;
			default:
				return "<p>This <a href='$cache_url'>geocache</a> description comes from the <a href='$site_url'>$site_name</a> site.</p>";
				break;
		}
	}
}
