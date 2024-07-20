<?php
require_once realpath(__DIR__ . '/../vendor/autoload.php');

use Barryvanveen\Lastfm\Lastfm;
use GuzzleHttp\Client;
use Symfony\Component\Cache\Adapter\FilesystemTagAwareAdapter;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

// define root path
define('ROOT_PATH', realpath(__DIR__ . '/../'));

// Looking for .env at the root directory
$dotenv = new Dotenv();

// you can also load several files
$app = [];
foreach (['.env', '.env.local', '.env.local.php'] as $file) {
  if (file_exists(ROOT_PATH.'/'.$file)) {
    $dotenv->load(ROOT_PATH.'/'.$file);
  }
}
if (!empty($_ENV['APP_ENV'])) {
  $env = $_ENV['APP_ENV'];
  switch ($env) {
    case 'dev':
      error_reporting(E_ALL);
      ini_set('display_errors', 1);
      break;
    case 'test':
      break;
    case 'prod':
      break;
  }

  foreach ([".env.{$env}", ".env.{$env}.local", ".env.{$env}.local.php"] as $file) {
    if (file_exists(ROOT_PATH.'/'.$file)) {
      $dotenv->load(ROOT_PATH.'/'.$file);
    }
  }
  $app['env'] = $_ENV['APP_ENV'];
  if (isset($_ENV['APP_DEBUG'])) {
    $app['debug'] = $_ENV['APP_DEBUG'];
  }
}

// init our cache
$app_cache = new FilesystemTagAwareAdapter($namespace ='app_cache', $default_lifetime = 3600, $directory = ROOT_PATH . "/var/cache/{$app['env']}");
$cache_beta = 1.0;

// get request
$request = Request::createFromGlobals();

// get request context
$context = new RequestContext();
$context->fromRequest($request);

// load templates
$loader = new \Twig\Loader\FilesystemLoader(ROOT_PATH . '/templates');
$twig = new \Twig\Environment($loader, [
  'debug' => ($_ENV['APP_DEBUG'] ?? false),
  'cache' => ROOT_PATH . "/var/cache/{$app['env']}/twig",
]);

$lastfm = ['current_track' => null, 'lastplayed' => []];
if ($request->server->has('LAST_FM_API_KEY')) {
  try {
    $api_key = $request->server->get('LAST_FM_API_KEY');
    if ($request->server->has('LAST_FM_USER')) {
      $lastfmUsername = $request->server->get('LAST_FM_USER');
    }
    $lastfmApi = new Lastfm(new Client(), $api_key);
    $lastfm = $app_cache->get('lastfm__account', function (ItemInterface $item) use ($lastfm, $lastfmApi, $lastfmUsername): ?array {
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
    
    $tracks = $app_cache->get('lastfm__tracks', function (ItemInterface $item) use ($lastfmApi, $lastfmUsername): ?array {
      $expiresAt = (new \DateTime())->setTimeZone(new \DateTimeZone('America/New_York'))->setTimestamp(strtotime('+30 seconds'));
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
      $song_date = (new \DateTime)->setTimeZone(new \DateTimeZone('America/New_York'))->setTimestamp($song_timestamp);
      $song_url = $track['url'];
      if ($current) {
        $lastfm['current_track'] = [
          'artist' => $track['artist']['#text'],
          'song' => $track['name'],
          'song_url' => $song_url,
          'date' => $song_date->format('d m y H:i a'),
        ];
      }
      else {
        $lastfm['lastplayed'][] = [
          'artist' => $track['artist']['#text'],
          'song' => $track['name'],
          'song_url' => $song_url,
          'date' => $song_date->format('d m y H:i a'),
        ];
      }
    }
  }
  catch (Barryvanveen\Lastfm\Exceptions\ResponseException $exception) {
    $lastfm['error'] = $exception->getMessage();
  }
}

// add tracks as a global
$twig->addGlobal('app', $app);
$twig->addGlobal('lastfm', $lastfm);

// define routes
$routes = new Routing\RouteCollection();
$routes->add('homepage', new Routing\Route('/', ['_controller' => 'HomepageController', 'method' => 'index']));

$url_generator = new Routing\Generator\UrlGenerator($routes, $context);
$twig->addFunction(new \Twig\TwigFunction('path', function($url) use ($url_generator) {
  return $url_generator->generate($url);
}));


$matcher = new Routing\Matcher\UrlMatcher($routes, $context);

try {
  $pathInfo = $request->getPathInfo();
  extract($matcher->match($request->getPathInfo()), EXTR_SKIP);
  ob_start();
  include sprintf(__DIR__.'/../src/pages/%s.php', $_route);
  $response = new Response(ob_get_clean(), 200);
} catch (Routing\Exception\ResourceNotFoundException $exception) {
  $template = $twig->render('errors\404.html.twig', ['path' => $request->getPathInfo()]);
  $response = new Response($template, 404);
} catch (Exception $exception) {
  $template = $twig->render('errors\500.html.twig', ['path' => $request->getPathInfo()]);
  $response = new Response($template, 404);
}
$response->send();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="canonical" href="https://spacecadetgrrl.me" />
  <meta name="description" content="katy's personal and professional website. ♡">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/js/all.min.js" integrity="sha512-u3fPA7V8qQmhBPNT5quvaXVa1mnnLSXUep5PS1qo5NRzHwG19aHmNJnj1Q8hpA/nBWZtZD4r4AX6YOt5ynLN2g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <link href="https://fonts.googleapis.com/css?family=VT323|Montserrat|Give+You+Glory" rel="stylesheet">
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.2.1/css/bootstrap.min.css" integrity="sha384-GJzZqFGwb1QTTN6wy59ffF1BuGJpLSa9DkKMp0DgiMDm4iYMj70gZWKYbI706tWS" crossorigin="anonymous">
  <link rel="stylesheet" href="css/root.css">
  <link rel="stylesheet" href="css/main.css">
  <title>♡ s p a c e c a d e t g r r l ♡</title>
</head>
<body>
  <div class="container">
    <div class="row">
      <div class="order-1 order-md-0 col-md-3">
        <article>
          <h1 class="title">hi there :)</h1>
          <p class="subtitle">dreamer, nerdy girl, ttrpg addict, dragon wrangler</p>
          <p>i love d&d, ttrpgs, cosplaying, renn faires, and <a href="https://archiveofourown.org/tags/Laudna*s*Imogen%20Temult/works" target="_blank">imodna</a></p>
        </article>
        <?php echo $twig->render('sidebar\nowplaying.html.twig', ['tracks' => $nowplaying]); ?>
        <article>
          <h1 class="title">socials</h1>
          <p class="subtitle">find me on social media. be warned, i hate it as much as you.</p>
          <ul class="socialmedia d-flex flex-column align-items-center align-items-md-start">
            <li><a href="https://bsky.app/profile/netkitten.net" target="_blank" rel="me"><i class="fa-brands fa-bluesky"></i> bluesky</a></li>
            <li><a href="https://tech.lgbt/@pixiekat" target="_blank" rel="me"><i class="fa-brands fa-mastodon"></i> mastodon</a></li>
            <li><a href="https://www.tumblr.com/devilishlyseraphic" target="_blank" rel="me"><i class="fa-brands fa-tumblr"></i> tumblr</a></li>
            <li><a href="https://open.spotify.com/user/6tio4kp0gx5q05o1vxxevnkv1" target="_blank" rel="me"><i class="fa-brands fa-spotify"></i> spotify</a></li>
            <li><a href="https://music.youtube.com/channel/UCBYckfzpn20NX-dPaQA26XQ" target="_blank" rel="me"><i class="fa-brands fa-youtube"></i> youtube music</a></li>
            <li><a href="https://github.com/pixiekat" target="_blank" rel="me"><i class="fa-brands fa-github"></i> github</a></li>
            <li><a href="https://www.instagram.com/cupcakezealot/" target="_blank" rel="me"><i class="fa-brands fa-instagram"></i> instagram</a></li>
            <li><a href="https://www.linkedin.com/in/katiewritescode/" target="_blank" rel="me"><i class="fa-brands fa-linkedin"></i> linkedin</a></li>
            <li><a href="https://www.threads.net/@cupcakezealot" target="_blank" rel="me"><i class="fa-brands fa-threads"></i> threads</a></li>
            <li>
              <a href="https://archiveofourown.org/users/devilishlyseraphic" target="_blank" rel="me">
                <svg fill="#990000" width="25px" height="40px" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg"><g id="SVGRepo_bgCarrier" stroke-width="0"></g><g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g><g id="SVGRepo_iconCarrier"> <path d="M24.557 13.177c-0.917 1.489-2.432 2.296-3.989 2.473-5.636 0.647-8.063-5.531-4.625-8.968 3.084-3.084 9.443-1.204 9.344 3.864-0.016 0.813-0.224 1.808-0.729 2.631zM15.943 10.473c0.193 4.792 6.355 4.907 7.584 1.401 1.088-3.093-1.496-5.593-4.256-5.192-2.025 0.291-3.401 1.88-3.328 3.791zM30.828 8.005c0.38-0.177 0.932-0.552 1.12-0.328 0.281 0.333-0.615 0.629-1 0.885-1.224 0.797-2.308 1.635-3.303 2.729-1.276 1.411-2.593 3.172-3.4 5.093 0.828 0.041 4.031 0.208 4.801 1.975 0.62 1.416-0.516 2.875-1.76 3.5 0.885 0.552 2.411 1.26 2.328 2.531-0.213 3.125-4.927 2.989-6.907 0.927-0.323-0.385-0.473-0.64-0.333-0.771 0.204-0.177 0.437 0.251 0.901 0.636 0.303 0.249 0.505 0.333 0.765 0.473 1.735 0.912 4.448 0.537 4.636-1.057 0.083-0.739-0.885-1.276-1.625-1.604-0.661-0.301-2.016-0.516-1.989-1.271 0.025-0.74 0.697-0.599 1.375-0.864 0.735-0.292 1.339-1.204 1.333-1.491-0.005-1.208-2.744-1.181-4.292-1.229-0.364 0.813-0.629 1.636-0.921 2.881-0.188 0.776-0.203 2.525-1 2.703-0.979 0.219-1.349-0.636-1.937-1.317-0.792-0.923-1.917-2.188-2.589-2.969-4.047 1.339-7.192 2.792-11.009 4.953-1.751 0.989-2.751 2.12-3.391 1.803-0.532-0.256-0.453-0.844-0.199-1.183 0.527-0.693 1.235-1.631 1.86-2.516 0.801-1.131 1.405-2.224 1.833-3.26 0.921-2.251 1.64-6.292 1.963-7.813 0.12-0.547 0.417-0.463 0.433-0.197 0.036 0.563-0.319 2.729-0.385 3.177-0.584 3.708-1.032 5.88-2.683 8.468 2.927-1.812 6.407-4.025 10.125-5.276-3.781-4.343-7.781-8.457-13.344-11.052-0.855-0.484-2.235-0.588-2.235-0.916 0-0.36 1.041-0.079 1.391 0.011 3.177 0.796 6.421 2.853 8.803 4.593 2.859 2.088 6.285 5.287 7.572 6.776 0.875-0.303 3.047-0.609 4.928-0.661 1-2.109 3.4-5.36 6.385-7.261 0.547-0.348 1.119-0.791 1.749-1.077zM19.369 18.765c0.568 0.573 0.991 1.245 1.485 1.865 0.228-0.787 0.536-1.62 0.853-2.344-0.823 0.109-1.708 0.271-2.339 0.479z"></path> </g></svg>

                archive of our own
              </a>
            </li>
            <li><i class="fa-brands fa-discord"></i> <small>devilishseraph#8433</small></a></li>
          </ul>
        </article>
      </div>
      <div class="order-0 order-md-1 col-md-9">
        <ul class="list--about-me">
          <li class="emoji emoji-wave">Hi, I'm Katherine Elizabeth, or <a href="https://tech.lgbt/@pixiekat" rel="me" target="_blank">@pixiekat</a></li>
          <li class="emoji emoji-education">I started web design in AOL Pages and Geocities in the mid nineties.</li>
          <li class="emoji emoji-chess">I'm proficient in PHP, Drupal (5+), Symfony, and Rails</li>
          <li class="emoji emoji-plant">I'm currently learning Flutter, React, Rust, Dart, Web Extension Development, Chrome Development, and Rails</li>
          <li class="emoji emoji-two-hearts">
            I love <a href="https://codeberg.org/teaserbot-labs/delightful-humane-design" target="_blank">ethnical tech</a>, <a href="https://www.a11yproject.com/" target="_blank">A11y</a> inspired designs, <a href="https://www.codereliant.io/failing-with-dignity/" target="_blank">graceful degradation</a>, and UX design. I support open web standards and the not for profit internet. My first computer was a <a href="https://www.vintagecomputing.com/index.php/archives/3073/retro-scan-the-tandy-sensation" target="_blank">Tandy Sensation</a>, I made my first website using <a href="https://mason.gmu.edu/~montecin/netcompose.html" target="_blank">Netscape Composer</a>, <a href="https://www.mozilla.org/" target="_blank">Mozilla</a> Firefox and <a href="https://www.zdnet.com/home-and-office/networking/the-beginning-of-the-peoples-web-20-years-of-netscape/" target="_blank">Netscape</a> supporter. I work in healthcare and love interacting and building with FHIR and rxNorm.
          </li>
          <li class="emoji emoji-plead">Would love to collaborate on Rails, Drupal, Symfony, or Flutter projects</li>
          <li class="emoji emoji-eyes">I love steampunk, cosplay, sewing, reading, and theatre tech</li>
          <li class="emoji emoji-cat">I have two cats, Callie the Calico and Winnie the Tortoiseshell.</li>
          <li class="emoji emoji-gaming">Obsessed with JRPGs. I have been playing games since the late 80s. My first console system was the Master System. I also love any Sierra Online or LucasArts game. Point and click adventures are my everything. I love anything Final Fantasy; I have most recently completed Final Fantasy VII Rebirth and Final Fantasy XVI</li>
          <li class="emoji emoji-facts">I think webrings and web cliques should come back in vogue and that web 1.0 was infinitely better than web 2.0+. I also think there should be a native facts emoji.</li>
          <li class="emoji emoji-travel">I love travelling and could spend hours on street view. I'm fascinated with out of the way and unknown places. I most recently visited Scotland for two weeks and I loved it. I want to live in Japan, Shetland, or Norway someday</li>
        </ul>

        <p class="signature">xoxo katy <i class="fas fa-heart"></i></p>
      </div>
    </div>
    <footer class="container-fluid my-3">
      <div class="row">
        <div class="col-md-12 text-center">
          <p>
            made with <i class="fas fa-heart"></i> with <a href="https://chrome.google.com" target="_blank"><i class="fa-brands fa-chrome"></i> chrome</a> and <a href="https://code.visualstudio.com/" target="_blank">vscode</a>.
          </p>
        </div>
      </div>
  </footer>
  <script src="https://code.jquery.com/jquery-3.3.1.slim.min.js" integrity="sha384-q8i/X+965DzO0rT7abK41JStQIAqVgRVzpbzo5smXKp4YfRvH+8abtTE1Pi6jizo" crossorigin="anonymous"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.6/umd/popper.min.js" integrity="sha384-wHAiFfRlMFy6i5SRaxvfOCifBUQy1xHdJ/yoi7FRNXMRBu5WHdZYu1hA6ZOblgut" crossorigin="anonymous"></script>
  <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.2.1/js/bootstrap.min.js" integrity="sha384-B0UglyR+jN6CkvvICOB2joaf5I4l3gm9GU6Hc1og6Ls7i6U/mkkaduKaBhlAXv9k" crossorigin="anonymous"></script>
  <!-- Google tag (gtag.js) -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-WVLYZ5CY5Y"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());

    gtag('config', 'G-WVLYZ5CY5Y');
  </script>
</body>
</html>