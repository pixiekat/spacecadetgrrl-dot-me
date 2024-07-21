<?php
require_once realpath(__DIR__ . '/../vendor/autoload.php');

use Barryvanveen\Lastfm\Lastfm;
use DebugBar\StandardDebugBar;
use GuzzleHttp\Client;
use PixiekatBootstrap\Bootstrap;
use Symfony\Component\Cache\Adapter\FilesystemTagAwareAdapter;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing;
use Symfony\Component\Routing\Exception;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

// define our routes
$routes = new Routing\RouteCollection();
$routes->add('homepage', new Routing\Route('/', ['_controller' => 'HomepageController', 'method' => 'index']));
$routes->add('domain-info', new Routing\Route('/site.html', ['_controller' => 'DomainInfoController', 'method' => 'index']));
$app = (new Bootstrap())->createApplication($routes);
$app_cache = $app->getCache('app');
$lastfm = ['current_track' => null, 'lastplayed' => []];
$request = $app->getCurrentRequest();
if ($request->server->has('LAST_FM_API_KEY')) {
  try {
    $api_key = $request->server->get('LAST_FM_API_KEY');
    if ($request->server->has('LAST_FM_USER')) {
      $lastfmUsername = $request->server->get('LAST_FM_USER');
    }
    $cache_beta = INF;
    if ($request->server->has('CACHE_DEFAULT_BETA')) {
      $cache_beta = (float) $request->server->get('CACHE_DEFAULT_BETA');
    }
    $lastfmApi = new Lastfm(new Client(), $api_key);
    $lastfm = $app_cache->get('lastfm__account', function (ItemInterface $item) use ($app, $lastfm, $lastfmApi, $lastfmUsername): ?array {
      $app->getLogger()->debug('LastFM account cache miss: refreshing from LastFm API');
      $expiresAt = (new \DateTime())->setTimeZone(new \DateTimeZone('America/New_York'))->setTimestamp(strtotime('+1 day'));
      $item->tag(['lastfm', 'api']);

      try {
        $lastfm['account'] = $lastfmApi->userInfo($lastfmUsername)->get();
      }
      catch (Barryvanveen\Lastfm\Exceptions\ResponseException $exception) {
        $item->expiresAt(time());
      }
      return $lastfm;
    }, $cache_beta);
    
    $tracks = $app_cache->get('lastfm__tracks', function (ItemInterface $item) use ($app, $lastfmApi, $lastfmUsername): ?array {
      $expiresAt = (new \DateTime())->setTimeZone(new \DateTimeZone('America/New_York'))->setTimestamp(strtotime('+30 seconds'));
      $app->getLogger()->debug('LastFM tracks cache miss: refreshing from LastFm API');
      $item->expiresAt($expiresAt);
      $item->tag(['lastfm', 'api']);
      try {
        $tracks = $lastfmApi->userRecentTracks('cupcakezealot')->limit(8)->get();
      }
      catch (Barryvanveen\Lastfm\Exceptions\ResponseException $exception) {
        $item->expiresAt(time());
      }
      return $tracks;
    }, $cache_beta);

    foreach ($tracks as $track) {
      $current = false;
      if (isset($track['date']['uts'])) {
        $song_timestamp = $track['date']['uts'];
      }
      else {
        $current = true;
        $song_timestamp = time();
      }
      $song_date = (new \DateTime)->setTimeZone(new \DateTimeZone('Europe/London'))->setTimestamp($song_timestamp);
      $song_url = $track['url'];
      if ($current) {
        $lastfm['current_track'] = [
          'artist' => $track['artist']['#text'],
          'song' => $track['name'],
          'song_url' => $song_url,
          'date' => $song_date->format('d F y H:i'),
        ];
      }
      else {
        $lastfm['lastplayed'][] = [
          'artist' => $track['artist']['#text'],
          'song' => $track['name'],
          'song_url' => $song_url,
          'date' => $song_date->format('d F y H:i'),
        ];
      }
    }
  }
  catch (Barryvanveen\Lastfm\Exceptions\ResponseException $exception) {
    $app->getLogger()->error($exception->getMessage());
    $lastfm['error'] = $exception->getMessage();
  }
}

// add tracks as a global
$app->setTwigGlobal('lastfm', $lastfm);

// run the app
$request = $app->getCurrentRequest();
$server = $request->server;
try {
  $rootPath = Bootstrap::getRootPath();
  $request = $app->getCurrentRequest();
  $pathInfo = $request->getPathInfo();
  extract($app->getUrlMatcher()->match($request->getPathInfo()), EXTR_SKIP);
  ob_start();
  include sprintf($rootPath.'/src/pages/%s.php', $_route);
  $response = new Response(ob_get_clean(), 200);
  $response->send();
} catch (Exception\ResourceNotFoundException $exception) {
  $app->getLogger()->error($exception->getMessage(), ['path' => $request->getPathInfo(), 'code' => 404, 'method' => $request->getMethod(), 'user_agent' => $request->headers->get('User-Agent'), 'ip' => $request->getClientIp()]);
  $template = $app->getTwig()->render('errors\404.html.twig', ['path' => $request->getPathInfo()]);
  $response = new Response($template, 404);
  $response->send();
} catch (\Exception $exception) {
  $app->getLogger()->error($exception->getMessage(), ['path' => $request->getPathInfo(), 'code' => 404, 'method' => $request->getMethod(), 'user_agent' => $request->headers->get('User-Agent'), 'ip' => $request->getClientIp()]);
  $template = $app->getTwig()->render('errors\500.html.twig', ['path' => $request->getPathInfo(), 'message' => $exception->getMessage()]);
  $response = new Response($template, 500);
  $response->send();
}
