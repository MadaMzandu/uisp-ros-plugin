<?php
require_once 'vendor/autoload.php';

/** The SDK's public factories retain UISP URL/certificate handling; all clients share transport policy. */
final class ApiUispClient
{
    public const REQUEST_TIMEOUT = 15.0;
    public const CONNECT_TIMEOUT = 5.0;

    public static function crm() { return self::configure(\Ubnt\UcrmPluginSdk\Service\UcrmApi::create()); }
    public static function network(string $token) { return self::configure(\Ubnt\UcrmPluginSdk\Service\UnmsApi::create($token)); }
    public static function security() { return self::configure(\Ubnt\UcrmPluginSdk\Service\UcrmSecurity::create()); }

    private static function configure($sdk)
    {
        // Isolated regression fixtures replace the SDK without loading an HTTP transport.
        // A real SDK factory cannot succeed without Guzzle, so a damaged installation fails before this branch.
        if (!class_exists(\GuzzleHttp\Client::class)) return $sdk;
        $property = new ReflectionProperty($sdk, 'client');
        if (PHP_VERSION_ID < 80100) $property->setAccessible(true);
        $client = $property->getValue($sdk);
        if (!$client instanceof \GuzzleHttp\Client) throw new RuntimeException('Unsupported UISP HTTP client');
        $options = $client->getConfig();
        $options['timeout'] = self::REQUEST_TIMEOUT;
        $options['connect_timeout'] = self::CONNECT_TIMEOUT;
        $options['read_timeout'] = self::REQUEST_TIMEOUT;
        $options['allow_redirects'] = false;
        $handler = \GuzzleHttp\HandlerStack::create();
        $handler->push(\GuzzleHttp\Middleware::mapResponse(function ($response) {
            if ($response->getStatusCode() >= 300 && $response->getStatusCode() < 400) {
                throw new RuntimeException('UISP API redirects are not allowed');
            }
            return $response;
        }), 'uisp_redirect_guard');
        $options['handler'] = $handler;
        $property->setValue($sdk, new \GuzzleHttp\Client($options));
        return $sdk;
    }
}
