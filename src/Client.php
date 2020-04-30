<?php

declare(strict_types=1);

namespace Schnitzler\BibApiClient;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Encoder\XmlEncoder;

final class Client
{
    /**
     * @var ClientInterface
     */
    private $httpClient;

    /**
     * @var UriInterface
     */
    private $baseUri;

    /**
     * @var RequestFactoryInterface
     */
    private $requestFactory;

    /**
     * @var StreamFactoryInterface
     */
    private $streamFactory;

    public function __construct(
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        ClientInterface $httpClient,
        UriInterface $baseUri
    ) {
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
        $this->httpClient = $httpClient;
        $this->baseUri = $baseUri;
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function login(string $username, string $password): ResponseInterface
    {
        $query = http_build_query([
            'method' => 'login',
            'user' => $username,
            'pw' => $password
        ]);
        $query = ltrim($query, '&');

        $uri = $this->baseUri->withQuery($query);
        $request = $this->requestFactory->createRequest('GET', $uri);
        return $this->sendRequest($request);
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function forgotPassword(string $sessionId, string $email): ResponseInterface
    {
        $query = http_build_query([
            'method' => 'passwortVergessen',
            'session_id' => $sessionId,
            'datensatz_kennung' => $email
        ]);
        $query = ltrim($query, '&');

        $uri = $this->baseUri->withQuery($query);
        $request = $this->requestFactory->createRequest('POST', $uri);
        return $this->sendRequest($request);
    }

    /**
     * @throws ClientExceptionInterface
     */
    private function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->httpClient->sendRequest($request);
        $response->getBody()->rewind();
        $content = $response->getBody()->getContents();

        $xmlEncoder = new XmlEncoder();
        $array = $xmlEncoder->decode($content, 'xml');

        $queries = [];
        parse_str($request->getUri()->getQuery(), $queries);

        $rootNodeName = $queries['method'] ?? null;

        if (($statusCode = ($array[$rootNodeName]['code'] ?? null)) !== null) {
            $response = $response->withStatus($statusCode);
        }

        if (isset($rootNodeName, $array[$rootNodeName]['data']) && \is_array($array[$rootNodeName]['data'])) {
            $data = $array[$rootNodeName]['data'];
            if ($rootNodeName === 'login' && isset($array[$rootNodeName]['session_id'])) {
                $data['_SESSION_ID'] = $array[$rootNodeName]['session_id'];
            }

            $jsonEncoder = new JsonEncoder();
            $json = $jsonEncoder->encode($data, 'json');
            $json = \is_string($json) ? $json : '';

            $body = $this->streamFactory->createStream($json);
            $body->rewind();
            $response = $response->withBody($body);
        }

        return $response;
    }
}
