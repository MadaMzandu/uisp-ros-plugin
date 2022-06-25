<!--
<?php
include_once 'includes/updates.php';
if(user_not_ok()){
    $status = [
        'status' => 'failed',
        'error' => true,
        'message' => 'User is not authenticated',
        'data' => []
    ];
    exit(json_encode($status));
}
?> -->
<!DOCTYPE html>
<html>
<head>
  <!--
    If you are serving your web app in a path other than the root, change the
    href value below to reflect the base path you are serving from.

    The path provided below has to start and end with a slash "/" in order for
    it to work correctly.

    For more details:
    * https://developer.mozilla.org/en-US/docs/Web/HTML/Element/base
  -->

 <!--   <base href="/"> -->
 <base href="/crm/_plugins/ros-plugin/public/panel/">

  <meta charset="UTF-8">
  <meta content="IE=Edge" http-equiv="X-UA-Compatible">
  <meta name="description" content="A new Flutter project.">

  <!-- iOS meta tags & icons -->
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black">
  <meta name="apple-mobile-web-app-title" content="rpa">
  <link rel="apple-touch-icon" href="icons/Icon-192.png">

  <title>ROS Plugin</title>
  <link rel="manifest" href="manifest.json">
  <link rel="stylesheet" type="text/css" href="splash/style.css">
</head>
<body style="position: fixed; inset: 0px; overflow: hidden; padding: 0px; margin: 0px; user-select: none; touch-action: none; font: 14px sans-serif; color: red;">
  <!-- This script installs service_worker.js to provide PWA functionality to
       application. For more information, see:
       https://developers.google.com/web/fundamentals/primers/service-workers -->
  <script>
    var serviceWorkerVersion = '150959289';
    var scriptLoaded = false;
    function loadMainDartJs() {
      if (scriptLoaded) {
        return;
      }
      scriptLoaded = true;
      var scriptTag = document.createElement('script');
      scriptTag.src = 'main.dart.js';
      scriptTag.type = 'application/javascript';
      document.body.append(scriptTag);
    }

    if ('serviceWorker' in navigator) {
      // Service workers are supported. Use them.
      window.addEventListener('load', function () {
        // Wait for registration to finish before dropping the <script> tag.
        // Otherwise, the browser will load the script multiple times,
        // potentially different versions.
        var serviceWorkerUrl = 'flutter_service_worker.js?v=' + serviceWorkerVersion;
        navigator.serviceWorker.register(serviceWorkerUrl)
          .then((reg) => {
            function waitForActivation(serviceWorker) {
              serviceWorker.addEventListener('statechange', () => {
                if (serviceWorker.state == 'activated') {
                  console.log('Installed new service worker.');
                  loadMainDartJs();
                }
              });
            }
            if (!reg.active && (reg.installing || reg.waiting)) {
              // No active web worker and we have installed or are installing
              // one for the first time. Simply wait for it to activate.
              waitForActivation(reg.installing ?? reg.waiting);
            } else if (!reg.active.scriptURL.endsWith(serviceWorkerVersion)) {
              // When the app updates the serviceWorkerVersion changes, so we
              // need to ask the service worker to update.
              console.log('New service worker available.');
              reg.update();
              waitForActivation(reg.installing);
            } else {
              // Existing service worker is still good.
              console.log('Loading app from service worker.');
              loadMainDartJs();
            }
          });

        // If service worker doesn't succeed in a reasonable amount of time,
        // fallback to plaint <script> tag.
        setTimeout(() => {
          if (!scriptLoaded) {
            console.warn(
              'Failed to load app from service worker. Falling back to plain <script> tag.',
            );
            loadMainDartJs();
          }
        }, 4000);
      });
    } else {
      // Service workers not supported. Just drop the <script> tag.
      loadMainDartJs();
    }
  </script>
  <picture id="splash">
    <source srcset="splash/img/light-1x.png 1x, splash/img/light-2x.png 2x, splash/img/light-3x.png 3x, splash/img/light-4x.png 4x" media="(prefers-color-scheme: light) or (prefers-color-scheme: no-preference)">
    <source srcset="splash/img/dark-1x.png 1x, splash/img/dark-2x.png 2x, splash/img/dark-3x.png 3x, splash/img/dark-4x.png 4x" media="(prefers-color-scheme: dark)">
    <img class="center" aria-hidden="true" src="splash/img/light-1x.png" />
  </picture>
</body>
</html>