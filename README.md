# lametric-adsense
Adsense for La Metric (unofficial)

Make file
<?php

require 'vendor/autoload.php';

$LMA = new \Dejurin\LaMetric_Adsense([
    'clientId' 	=> '', // Google Client Id 
    'clientSecret' => '', // Google Client Secret
    'redirectUri' => 'http://localhost:8000/'.basename(__FILE__).'?auth',
    'accessType' => 'offline',
],
'', // La Metric: Access Token
'', // La Metric: URL for pushing data to all 
__DIR__.'/db', // DB path
basename(__FILE__) // current filename
);

if (isset($_GET['auth'])) {
    $LMA->auth();
} elseif (isset($_GET['accounts'])) {
    $LMA->accounts();
} elseif (isset($_GET['show'])) {
    $LMA->show();
} elseif (isset($_GET['push'])) {
    $LMA->push();
} else {
	$LMA->index();
}
