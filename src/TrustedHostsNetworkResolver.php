<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware;

use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Yiisoft\Http\HeaderValueHelper;
use Yiisoft\NetworkUtilities\IpHelper;
use Yiisoft\Validator\Rule\Ip;
use Yiisoft\Validator\ValidatorInterface;

use function array_diff;
use function array_pad;
use function array_reverse;
use function array_shift;
use function array_unshift;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_callable;
use function filter_var;
use function is_string;
use function preg_match;
use function str_replace;
use function strtolower;
use function trim;

/**
 * Trusted hosts network resolver.
 *
 * ```php
 * (new TrustedHostsNetworkResolver($responseFactory))
 * ->withAddedTrustedHosts(
 *   // List of secure hosts including $_SERVER['REMOTE_ADDR'], can specify IPv4, IPv6, domains and aliases {@see Ip}.
 *   ['1.1.1.1', '2.2.2.1/3', '2001::/32', 'localhost'].
 *   // IP list headers. For advanced handling headers {@see TrustedHostsNetworkResolver::IP_HEADER_TYPE_RFC7239}.
 *   // Headers containing multiple sub-elements (e.g. RFC 7239) must also be listed for other relevant types
 *   // (e.g. host headers), otherwise they will only be used as an IP list.
 *   ['x-forwarded-for', [TrustedHostsNetworkResolver::IP_HEADER_TYPE_RFC7239, 'forwarded']]
 *   // Protocol headers with accepted protocols and values. Matching of values is case-insensitive.
 *   ['front-end-https' => ['https' => 'on']],
 *   // Host headers
 *   ['forwarded', 'x-forwarded-for']
 *   // URL headers
 *   ['x-rewrite-url'],
 *   // Port headers
 *   ['x-rewrite-port'],
 *   // Trusted headers. It is a good idea to list all relevant headers.
 *   ['x-forwarded-for', 'forwarded', ...],
 * );
 * ```
 */
class TrustedHostsNetworkResolver implements MiddlewareInterface
{
    public const IP_HEADER_TYPE_RFC7239 = 'rfc7239';

    public const DEFAULT_TRUSTED_HEADERS = [
        // common:
        'x-forwarded-for',
        'x-forwarded-host',
        'x-forwarded-proto',
        'x-forwarded-port',

        // RFC:
        'forward',

        // Microsoft:
        'front-end-https',
        'x-rewrite-url',
    ];

    private const DATA_KEY_HOSTS = 'hosts';
    private const DATA_KEY_IP_HEADERS = 'ipHeaders';
    private const DATA_KEY_HOST_HEADERS = 'hostHeaders';
    private const DATA_KEY_URL_HEADERS = 'urlHeaders';
    private const DATA_KEY_PROTOCOL_HEADERS = 'protocolHeaders';
    private const DATA_KEY_TRUSTED_HEADERS = 'trustedHeaders';
    private const DATA_KEY_PORT_HEADERS = 'portHeaders';

    private array $trustedHosts = [];
    private ?string $attributeIps = null;

    public function __construct(private ValidatorInterface $validator)
    {
    }

    /**
     * Returns a new instance with the added trusted hosts and related headers.
     *
     * The header lists are evaluated in the order they were specified.
     * If you specify multiple headers by type (e.g. IP headers), you must ensure that the irrelevant header is removed
     * e.g. web server application, otherwise spoof clients can be use this vulnerability.
     *
     * @param string[] $hosts List of trusted hosts IP addresses. If {@see isValidHost()} method is extended,
     * then can use domain names with reverse DNS resolving e.g. yiiframework.com, * .yiiframework.com.
     * @param array $ipHeaders List of headers containing IP lists.
     * @param array $protocolHeaders List of headers containing protocol. e.g.
     * ['x-forwarded-for' => ['http' => 'http', 'https' => ['on', 'https']]].
     * @param string[] $hostHeaders List of headers containing HTTP host.
     * @param string[] $urlHeaders List of headers containing HTTP URL.
     * @param string[] $portHeaders List of headers containing port number.
     * @param string[]|null $trustedHeaders List of trusted headers. Removed from the request, if in checking process
     * are classified as untrusted by hosts.
     *
     * @return self
     */
    public function withAddedTrustedHosts(
        array $hosts,
        // Defining default headers is not secure!
        array $ipHeaders = [],
        array $protocolHeaders = [],
        array $hostHeaders = [],
        array $urlHeaders = [],
        array $portHeaders = [],
        ?array $trustedHeaders = null
    ): self {
        $new = clone $this;

        foreach ($ipHeaders as $ipHeader) {
            if (is_string($ipHeader)) {
                continue;
            }

            if (!is_array($ipHeader)) {
                throw new InvalidArgumentException('Type of IP header is not a string and not array.');
            }

            if (count($ipHeader) !== 2) {
                throw new InvalidArgumentException('The IP header array must have exactly 2 elements.');
            }

            [$type, $header] = $ipHeader;

            if (!is_string($type)) {
                throw new InvalidArgumentException('The IP header type is not a string.');
            }

            if (!is_string($header)) {
                throw new InvalidArgumentException('The IP header value is not a string.');
            }

            if ($type === self::IP_HEADER_TYPE_RFC7239) {
                continue;
            }

            throw new InvalidArgumentException("Not supported IP header type: $type.");
        }

        if ($hosts === []) {
            throw new InvalidArgumentException('Empty hosts not allowed.');
        }

        $trustedHeaders = $trustedHeaders ?? self::DEFAULT_TRUSTED_HEADERS;
        $protocolHeaders = $this->prepareProtocolHeaders($protocolHeaders);

        $this->checkTypeStringOrArray($hosts, 'hosts');
        $this->checkTypeStringOrArray($trustedHeaders, 'trustedHeaders');
        $this->checkTypeStringOrArray($hostHeaders, 'hostHeaders');
        $this->checkTypeStringOrArray($urlHeaders, 'urlHeaders');
        $this->checkTypeStringOrArray($portHeaders, 'portHeaders');

        foreach ($hosts as $host) {
            $host = str_replace('*', 'wildcard', $host); // wildcard is allowed in host

            if (filter_var($host, FILTER_VALIDATE_DOMAIN) === false) {
                throw new InvalidArgumentException("\"$host\" host is not a domain and not an IP address.");
            }
        }

        $new->trustedHosts[] = [
            self::DATA_KEY_HOSTS => $hosts,
            self::DATA_KEY_IP_HEADERS => $ipHeaders,
            self::DATA_KEY_PROTOCOL_HEADERS => $protocolHeaders,
            self::DATA_KEY_TRUSTED_HEADERS => $trustedHeaders,
            self::DATA_KEY_HOST_HEADERS => $hostHeaders,
            self::DATA_KEY_URL_HEADERS => $urlHeaders,
            self::DATA_KEY_PORT_HEADERS => $portHeaders,
        ];

        return $new;
    }

    /**
     * Returns a new instance without the trusted hosts and related headers.
     *
     * @return self
     */
    public function withoutTrustedHosts(): self
    {
        $new = clone $this;
        $new->trustedHosts = [];
        return $new;
    }

    /**
     * Returns a new instance with the specified request's attribute name to which trusted path data is added.
     *
     * @param string|null $attribute The request attribute name.
     *
     * @see getElementsByRfc7239()
     *
     * @return self
     */
    public function withAttributeIps(?string $attribute): self
    {
        if ($attribute === '') {
            throw new RuntimeException('Attribute should not be empty string.');
        }

        $new = clone $this;
        $new->attributeIps = $attribute;
        return $new;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $actualHost = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        if ($actualHost === null) {
            // Validation is not possible.
            return $this->handleNotTrusted($request, $handler);
        }

        $trustedHostData = null;
        $trustedHeaders = [];

        foreach ($this->trustedHosts as $data) {
            // collect all trusted headers
            $trustedHeaders = array_merge($trustedHeaders, $data[self::DATA_KEY_TRUSTED_HEADERS]);

            if ($trustedHostData !== null) {
                // trusted hosts already found
                continue;
            }

            if ($this->isValidHost($actualHost, $data[self::DATA_KEY_HOSTS])) {
                $trustedHostData = $data;
            }
        }

        /** @psalm-suppress PossiblyNullArgument, PossiblyNullArrayAccess */
        $untrustedHeaders = array_diff($trustedHeaders, $trustedHostData[self::DATA_KEY_TRUSTED_HEADERS] ?? []);
        $request = $this->removeHeaders($request, $untrustedHeaders);

        if ($trustedHostData === null) {
            // No trusted host at all.
            return $this->handleNotTrusted($request, $handler);
        }

        [$ipListType, $ipHeader, $hostList] = $this->getIpList($request, $trustedHostData[self::DATA_KEY_IP_HEADERS]);
        $hostList = array_reverse($hostList); // the first item should be the closest to the server

        if ($ipListType === null) {
            $hostList = $this->getFormattedIpList($hostList);
        } elseif ($ipListType === self::IP_HEADER_TYPE_RFC7239) {
            $hostList = $this->getElementsByRfc7239($hostList);
        }

        array_unshift($hostList, ['ip' => $actualHost]); // server's ip to first position
        $hostDataList = [];

        do {
            $hostData = array_shift($hostList);
            if (!isset($hostData['ip'])) {
                $hostData = $this->reverseObfuscate($hostData, $hostDataList, $hostList, $request);

                if ($hostData === null) {
                    continue;
                }

                if (!isset($hostData['ip'])) {
                    break;
                }
            }

            $ip = $hostData['ip'];

            if (!$this->isValidHost($ip, ['any'])) {
                // invalid IP
                break;
            }

            $hostDataList[] = $hostData;

            if (!$this->isValidHost($ip, $trustedHostData[self::DATA_KEY_HOSTS])) {
                // not trusted host
                break;
            }
        } while (count($hostList) > 0);

        if ($this->attributeIps !== null) {
            $request = $request->withAttribute($this->attributeIps, $hostDataList);
        }

        $uri = $request->getUri();
        // find HTTP host
        foreach ($trustedHostData[self::DATA_KEY_HOST_HEADERS] as $hostHeader) {
            if (!$request->hasHeader($hostHeader)) {
                continue;
            }

            if (
                $hostHeader === $ipHeader
                && $ipListType === self::IP_HEADER_TYPE_RFC7239
                && isset($hostData['httpHost'])
            ) {
                $uri = $uri->withHost($hostData['httpHost']);
                break;
            }

            $host = $request->getHeaderLine($hostHeader);

            if (filter_var($host, FILTER_VALIDATE_DOMAIN) !== false) {
                $uri = $uri->withHost($host);
                break;
            }
        }

        // find protocol
        foreach ($trustedHostData[self::DATA_KEY_PROTOCOL_HEADERS] as $protocolHeader => $protocols) {
            if (!$request->hasHeader($protocolHeader)) {
                continue;
            }

            if (
                $protocolHeader === $ipHeader
                && $ipListType === self::IP_HEADER_TYPE_RFC7239
                && isset($hostData['protocol'])
            ) {
                $uri = $uri->withScheme($hostData['protocol']);
                break;
            }

            $protocolHeaderValue = $request->getHeaderLine($protocolHeader);

            foreach ($protocols as $protocol => $acceptedValues) {
                if (in_array($protocolHeaderValue, $acceptedValues, true)) {
                    $uri = $uri->withScheme($protocol);
                    break 2;
                }
            }
        }

        $urlParts = $this->getUrl($request, $trustedHostData[self::DATA_KEY_URL_HEADERS]);

        if ($urlParts !== null) {
            [$path, $query] = $urlParts;
            $uri = $uri->withPath($path);

            if ($query !== null) {
                $uri = $uri->withQuery($query);
            }
        }

        // find port
        foreach ($trustedHostData[self::DATA_KEY_PORT_HEADERS] as $portHeader) {
            if (!$request->hasHeader($portHeader)) {
                continue;
            }

            if (
                $portHeader === $ipHeader
                && $ipListType === self::IP_HEADER_TYPE_RFC7239
                && isset($hostData['port'])
                && $this->checkPort((string) $hostData['port'])
            ) {
                $uri = $uri->withPort($hostData['port']);
                break;
            }

            $port = $request->getHeaderLine($portHeader);

            if ($this->checkPort($port)) {
                $uri = $uri->withPort((int) $port);
                break;
            }
        }

        return $handler->handle($request
            ->withUri($uri)
            ->withAttribute('requestClientIp', $hostData['ip'] ?? null));
    }

    /**
     * Validate host by range.
     *
     * This method can be extendable by overwriting e.g. with reverse DNS verification.
     */
    protected function isValidHost(string $host, array $ranges): bool
    {
        $validationResult = $this->validator->validate(
            ['host' => $host],
            ['host' => [new Ip(ranges: $ranges, allowNegation: false, allowSubnet: false)]]
        );
        return $validationResult->isValid();
    }

    /**
     * Reverse obfuscating host data
     *
     * RFC 7239 allows to use obfuscated host data. In this case, either specifying the
     * IP address or dropping the proxy endpoint is required to determine validated route.
     *
     * By default it does not perform any transformation on the data. You can override this method.
     *
     * @param array $hostData
     * @param array $hostDataListValidated
     * @param array $hostDataListRemaining
     * @param RequestInterface $request
     *
     * @return array|null reverse obfuscated host data or null.
     * In case of null data is discarded and the process continues with the next portion of host data.
     * If the return value is an array, it must contain at least the `ip` key.
     *
     * @see getElementsByRfc7239()
     * @link https://tools.ietf.org/html/rfc7239#section-6.2
     * @link https://tools.ietf.org/html/rfc7239#section-6.3
     */
    protected function reverseObfuscate(
        array $hostData,
        array $hostDataListValidated,
        array $hostDataListRemaining,
        RequestInterface $request
    ): ?array {
        return $hostData;
    }

    private function handleNotTrusted(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        if ($this->attributeIps !== null) {
            $request = $request->withAttribute($this->attributeIps, null);
        }

        return $handler->handle($request->withAttribute('requestClientIp', null));
    }

    private function prepareProtocolHeaders(array $protocolHeaders): array
    {
        $output = [];

        foreach ($protocolHeaders as $header => $protocolAndAcceptedValues) {
            $header = strtolower($header);

            if (is_callable($protocolAndAcceptedValues)) {
                $output[$header] = $protocolAndAcceptedValues;
                continue;
            }

            if (!is_array($protocolAndAcceptedValues)) {
                throw new RuntimeException('Accepted values is not an array nor callable.');
            }

            if ($protocolAndAcceptedValues === []) {
                throw new RuntimeException('Accepted values cannot be an empty array.');
            }

            $output[$header] = [];

            foreach ($protocolAndAcceptedValues as $protocol => $acceptedValues) {
                if (!is_string($protocol)) {
                    throw new RuntimeException('The protocol must be a string.');
                }

                if ($protocol === '') {
                    throw new RuntimeException('The protocol cannot be empty.');
                }

                $output[$header][$protocol] = array_map('\strtolower', (array)$acceptedValues);
            }
        }

        return $output;
    }

    private function removeHeaders(ServerRequestInterface $request, array $headers): ServerRequestInterface
    {
        foreach ($headers as $header) {
            $request = $request->withoutAttribute($header);
        }

        return $request;
    }

    private function getIpList(RequestInterface $request, array $ipHeaders): array
    {
        foreach ($ipHeaders as $ipHeader) {
            $type = null;

            if (is_array($ipHeader)) {
                $type = array_shift($ipHeader);
                $ipHeader = array_shift($ipHeader);
            }

            if ($request->hasHeader($ipHeader)) {
                return [$type, $ipHeader, $request->getHeader($ipHeader)];
            }
        }

        return [null, null, []];
    }

    /**
     * @see getElementsByRfc7239
     */
    private function getFormattedIpList(array $forwards): array
    {
        $list = [];

        foreach ($forwards as $ip) {
            $list[] = ['ip' => $ip];
        }

        return $list;
    }

    /**
     * Forwarded elements by RFC7239.
     *
     * The structure of the elements:
     * - `host`: IP or obfuscated hostname or "unknown"
     * - `ip`: IP address (only if presented)
     * - `by`: used user-agent by proxy (only if presented)
     * - `port`: port number received by proxy (only if presented)
     * - `protocol`: protocol received by proxy (only if presented)
     * - `httpHost`: HTTP host received by proxy (only if presented)
     *
     * The list starts with the server and the last item is the client itself.
     *
     * @link https://tools.ietf.org/html/rfc7239
     *
     * @return array Proxy data elements.
     */
    private function getElementsByRfc7239(array $forwards): array
    {
        $list = [];

        foreach ($forwards as $forward) {
            $data = HeaderValueHelper::getParameters($forward);

            if (!isset($data['for'])) {
                // Invalid item, the following items will be dropped
                break;
            }

            $pattern = '/^(?<host>' . IpHelper::IPV4_PATTERN . '|unknown|_[\w\.-]+|[[]'
                . IpHelper::IPV6_PATTERN . '[]])(?::(?<port>[\w\.-]+))?$/';

            if (preg_match($pattern, $data['for'], $matches) === 0) {
                // Invalid item, the following items will be dropped
                break;
            }

            $ipData = [];
            $host = $matches['host'];
            $obfuscatedHost = $host === 'unknown' || str_starts_with($host, '_');

            if (!$obfuscatedHost) {
                // IPv4 & IPv6
                $ipData['ip'] = str_starts_with($host, '[') ? trim($host /* IPv6 */, '[]') : $host;
            }

            $ipData['host'] = $host;

            if (isset($matches['port'])) {
                $port = $matches['port'];

                if (!$obfuscatedHost && !$this->checkPort($port)) {
                    // Invalid port, the following items will be dropped
                    break;
                }

                $ipData['port'] = $obfuscatedHost ? $port : (int)$port;
            }

            // copy other properties
            foreach (['proto' => 'protocol', 'host' => 'httpHost', 'by' => 'by'] as $source => $destination) {
                if (isset($data[$source])) {
                    $ipData[$destination] = $data[$source];
                }
            }

            if (isset($ipData['httpHost']) && filter_var($ipData['httpHost'], FILTER_VALIDATE_DOMAIN) === false) {
                // remove not valid HTTP host
                unset($ipData['httpHost']);
            }

            $list[] = $ipData;
        }

        return $list;
    }

    private function getUrl(RequestInterface $request, array $urlHeaders): ?array
    {
        foreach ($urlHeaders as $header) {
            if (!$request->hasHeader($header)) {
                continue;
            }

            $url = $request->getHeaderLine($header);

            if (str_starts_with($url, '/')) {
                return array_pad(explode('?', $url, 2), 2, null);
            }
        }

        return null;
    }

    private function checkPort(string $port): bool
    {
        return preg_match('/^\d{1,5}$/', $port) === 1 && (int)$port <= 65535;
    }

    private function checkTypeStringOrArray(array $array, string $field): void
    {
        foreach ($array as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException("$field must be string type");
            }

            if (trim($item) === '') {
                throw new InvalidArgumentException("$field cannot be empty strings");
            }
        }
    }
}
