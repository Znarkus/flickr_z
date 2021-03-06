<?php

error_reporting(E_ALL | E_STRICT);
date_default_timezone_set('Europe/Stockholm');
header('Content-type: text/html; charset=utf-8');
define('LIB_PATH', '../lib/');

require LIB_PATH . 'klein/klein.php';
require LIB_PATH . 'dpzflickr/src/DPZ/Flickr.php';

// Init
respond(function ($request, $response, $app) {
	$app->flickr = new \DPZ\Flickr('e5a39e36014d64a73c037107d2d528c2', '49ac560af650535f', 'http://' . $_SERVER['SERVER_NAME'] . '/oauth');
	$app->flickrCall = function ($method, $params = array()) use ($app) {
		$rid = $method . json_encode($params);
		
		if (!isset($_SESSION['cache'][$rid])) {
			$_SESSION['cache'][$rid] = $app->flickr->call($method, $params);
		}
		
		return $_SESSION['cache'][$rid];
	};
	
	// Helper functions
	$response->flickrImageUrl = function ($data, $id, $size) {
		$size = $size ? "_{$size}" : $size;
		return "http://farm{$data['farm']}.staticflickr.com/{$data['server']}/{$id}_{$data['secret']}{$size}.jpg";
	};
	$response->clipboard = &$_SESSION['clipboard'];
	
	// Redirect to /auth
	//var_dump(parse_url($request->uri(), PHP_URL_PATH) !== '/auth', !$app->flickr->isAuthenticated());die;
	if (!in_array(parse_url($request->uri(), PHP_URL_PATH), array('/auth', '/oauth')) && !$app->flickr->isAuthenticated()) {
		session_destroy();
		session_start();
		
		$response->redirect('/auth?return=' . urlencode($request->uri()));
	}
	
});

// Sets
respond('/', function ($request, $response, $app) {
	$sets = $app->flickrCall('flickr.photosets.getList'/*, array('page' => 1, 'per_page' => 50)*/);
	$page = $request->param('page', 1);
	
	//var_dump($app->flickr->call('flickr.photos.getSizes', array('photo_id' => $sets['photosets']['photoset'][0]['primary'])));
    $response->render('pages/sets.phtml', array(
    	'sets' => array_slice($sets['photosets']['photoset'], 50 * ($page - 1), 50),
    	'pages' => ceil($sets['photosets']['total'] / 50),
    	'current_page' => $page
    ));
});

// A set
respond('/set/[i:id]', function (_Request $request, _Response $response, _App $app) {
	$setId = $request->param('id');
	$sets = $app->flickrCall('flickr.photosets.getList');
	
	for ($i = 0; $i < $sets['photosets']['total']; $i++) {
		$set = $sets['photosets']['photoset'][$i];
		
		if ($set['id'] == $setId) {
			$start = max($i - 10, 0);
			//$end = min($i + 10, $sets['photosets']['total']);
			$sets = array_slice($sets['photosets']['photoset'], $start, 21);
			break;
		}
	}
	
	//var_dump($sets);die;
	
    $response->render('pages/set.phtml', array(
    	'info' => $app->flickrCall('flickr.photosets.getInfo', array('photoset_id' => $setId)),
    	'photos' => $app->flickrCall('flickr.photosets.getPhotos', array('photoset_id' => $setId)),
    	'sets' => $sets
    ));
});


// Clipboard
respond('/clipboard', function (_Request $request, _Response $response, _App $app) {
	$response->render('pages/clipboard.phtml', array(
		'clipboard' => isset($_SESSION['clipboard']) ? $_SESSION['clipboard'] : array()
	));
});

// Clipboard download
respond('/clipboard/download/[:stage]', function (_Request $request, _Response $response, _App $app) {
	set_time_limit(0); // unlimited max execution time
	
	/*if (!isset($_SESSION['clipboard_download_dir'])) {
		//$_SESSION['clipboard']['download_dir'] = tempnam(sys_get_temp_dir(), 'flickr');
		$_SESSION['clipboard_download_dir'] = '../tmp/' . session_id() . '/';
		mkdir($_SESSION['clipboard_download_dir']);
	}*/
	
	$download_dir = session_id() . '/';
	$download_path = '../tmp/' . $download_dir;
	$zip_file = 'clipboard.zip';
	
	if (!is_dir($download_path)) {
		mkdir($download_path, 0777, true);
	}
	
	if (is_numeric($request->stage)) {
		$keys = array_keys($_SESSION['clipboard']);
		$photo = &$_SESSION['clipboard'][$request->stage];
		
		if (!isset($photo['sizes'])) {
			$r = $app->flickrCall('flickr.photos.getSizes', array('photo_id' => $photo['id']));
    		$photo['sizes'] = $r['sizes']['size'];
		}
		
		$flickr_url = $photo['sizes'][count($photo['sizes']) - 1]['source'];
		//$file = tempnam(sys_get_temp_dir(), 'flickr');
		$file = basename($flickr_url);
		
		if (!is_file($download_path . $file)) {
			$fh = fopen($download_path . $file, 'w');
			
			$options = array(
				CURLOPT_FILE    => $fh,
				CURLOPT_TIMEOUT => 28800, // set this to 8 hours so we dont timeout on big files
				CURLOPT_URL     => $flickr_url,
			);

			$ch = curl_init();
			curl_setopt_array($ch, $options);
			curl_exec($ch);
		}
		
		$photo['downloaded'] = $file;
		$response->json(array('success' => true));
		
	} else if ($request->stage === 'package') {
		if (is_file($download_path . $zip_file)) {
			unlink($download_path . $zip_file);
		}
		
		$files = array();
		
		foreach ($_SESSION['clipboard'] as $photo) {
			$files[] = $photo['downloaded'];
		}
		
		if (PHP_OS == 'WINNT') {
			$bin = realpath('zip.exe');
		} else {
			$bin = 'zip';
		}
		
		$cwd = getcwd();
		chdir($download_path);
		$cmd = $bin . ' -0 ' . $zip_file . ' ' . implode(' ', $files);
		var_dump($cmd);
		exec($cmd, $o, $r);
		chdir($cwd);
		
		if ($r) {
			throw new Exception("Error code {$r} while zipping");
		}
		
		$response->json(array('success' => true));
	
	} else if ($request->stage === 'download') {
		$response->header('X-Accel-Redirect', '/f/' . $download_dir . $zip_file);
		$response->header('Content-Disposition', 'attachment; filename=' . $zip_file);
		//$response->header('X-Accel-Limit-Rate', 1024 * 50);	// 50kB/s
		$response->header('Content-type', '');
	
	}
});

// Clipboard toggle
respond('POST', '/clipboard/toggle', function (_Request $request, _Response $response, _App $app) {
	$photo = $request->param('photo');
	
    if (isset($_SESSION['clipboard'][$photo['id']])) {
		unset($_SESSION['clipboard'][$photo['id']]);
    } else {
		$_SESSION['clipboard'][$photo['id']] = $photo;
    }
    
    $response->json(array(
    	'clipboard' => $_SESSION['clipboard'],
    	'success' => true
    ));
});

// Log in
respond('/auth', function (_Request $request, _Response $response, _App $app) {
	if ($request->param('return')) {
		$_SESSION['auth_return'] = $request->param('return');
	}
	
	$response->render('pages/auth.phtml');
});

// Oauth
respond('/oauth', function (_Request $request, _Response $response, _App $app) {
	if ($app->flickr->authenticate('read')) {
		$return = isset($_SESSION['auth_return']) ? $_SESSION['auth_return'] : '/';
		unset($_SESSION['auth_return']);
		$response->redirect($return);
	}
	
	/**/
	
	//die('Failed to auth to flickr');
	/*$userNsid = $flickr->getOauthData(\DPZ\Flickr::USER_NSID);
	$userName = $flickr->getOauthData(\DPZ\Flickr::USER_NAME);
	$userFullName = $flickr->getOauthData(\DPZ\Flickr::USER_FULL_NAME);*/

	/*$parameters =  array(
	    'per_page' => 100,
	    'extras' => 'url_sq,path_alias',
	);

	$r = $flickr->call('flickr.photosets.getList', $parameters);*/
	
	//var_dump($userFullName, $userName, $userNsid, $r);
});

// Log out
respond('/log_out', function ($request, $response, $app) {
	$app->flickr->signout();
	unset($_SESSION['cache']);
	//$response->redirect($request->param('return'));
	$response->redirect('/');
});

respond('404', function () {
	echo '404, sorry.';
});

dispatch();

/*header('content-type: text/plain');
var_dump($_SERVER);*/