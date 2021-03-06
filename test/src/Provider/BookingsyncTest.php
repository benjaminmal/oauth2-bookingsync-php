<?php

namespace Bookingsync\OAuth2\Client\Test\Provider;

use Bookingsync\OAuth2\Client\Provider\BookingSyncProvider;
use GuzzleHttp\ClientInterface;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class BookingsyncTest extends TestCase
{
    protected $provider;

    protected function setUp(): void
    {
        $this->provider = new BookingSyncProvider([
            'clientId' => 'mock_client_id',
            'clientSecret' => 'mock_secret',
            'redirectUri' => 'none',
        ]);
    }

    public function testGetAuthorizationUrl()
    {
        $url = $this->provider->getAuthorizationUrl();
        $uri = parse_url($url);
        parse_str($uri['query'], $query);

        $this->assertArrayHasKey('client_id', $query);
        $this->assertArrayHasKey('redirect_uri', $query);
        $this->assertArrayHasKey('state', $query);
        $this->assertArrayHasKey('scope', $query);
        $this->assertArrayHasKey('response_type', $query);
        $this->assertArrayHasKey('approval_prompt', $query);
        $this->assertNotNull($this->provider->getState());
    }

    public function testGetBaseAuthorizationUrl()
    {
        $url = $this->provider->getBaseAuthorizationUrl();
        $uri = parse_url($url);

        $this->assertEquals('/oauth/authorize', $uri['path']);
    }

    public function testGetBaseAccessTokenUrl()
    {
        $url = $this->provider->getBaseAccessTokenUrl();
        $uri = parse_url($url);

        $this->assertEquals('/oauth/token', $uri['path']);
    }

    public function testGetResourceOwnerDetailsUrl()
    {
        $accessTokenBody = [
            "access_token" => "mock_access_token",
            "expires" => 3600,
            "refresh_token" => "mock_refresh_token",
            "uid" => 1
        ];

        $response = m::mock(ResponseInterface::class);
        $response->shouldReceive('getHeader')->times(1)->andReturn('application/json');
        $response->shouldReceive('getStatusCode')->times(1)->andReturn(200);
        $response->shouldReceive('getBody')->times(1)->andReturn(json_encode($accessTokenBody));

        $client = m::mock(ClientInterface::class);
        $client->shouldReceive('setBaseUrl')->times(1);
        $client->shouldReceive('send')->times(1)->andReturn($response);
        $this->provider->setHttpClient($client);

        $token = $this->provider->getAccessToken('authorization_code', ['code' => 'mock_authorization_code']);
        $url = $this->provider->getResourceOwnerDetailsUrl($token);

        $uri = parse_url($url);

        $this->assertEquals('/api/v3/accounts', $uri['path']);
    }

    public function testGetAccessToken()
    {
        $accessTokenBody = [
            "access_token" => "mock_access_token",
            "expires" => 3600,
            "refresh_token" => "mock_refresh_token",
            "uid" => 1
        ];

        $response = m::mock(ResponseInterface::class);
        $response->shouldReceive('getHeader')->times(1)->andReturn('application/json');
        $response->shouldReceive('getStatusCode')->times(1)->andReturn(200);
        $response->shouldReceive('getBody')->times(1)->andReturn(json_encode($accessTokenBody));

        $client = m::mock(ClientInterface::class);
        $client->shouldReceive('setBaseUrl')->times(1);
        $client->shouldReceive('send')->times(1)->andReturn($response);
        $this->provider->setHttpClient($client);

        $token = $this->provider->getAccessToken('authorization_code', ['code' => 'mock_authorization_code']);

        $this->assertEquals('mock_access_token', $token->getToken());
        $this->assertLessThanOrEqual(time() + 3600, $token->getExpires());
        $this->assertFalse($token->hasExpired());
        $this->assertGreaterThanOrEqual(time(), $token->getExpires());
        $this->assertEquals('mock_refresh_token', $token->getRefreshToken());
        $this->assertEquals('1', $token->getResourceOwnerId());
    }

    public function testUserData()
    {
        $accessTokenBody = [
            "access_token" => "mock_access_token",
            "token_type" => "Bearer",
            "expires" => 3600,
            "refresh_token" => "mock_refresh_token",
            "scope" => "scope1 scope2"
        ];

        $postResponse = m::mock(ResponseInterface::class);
        $postResponse->shouldReceive('getHeader')->times(1)->andReturn('application/json');
        $postResponse->shouldReceive('getStatusCode')->times(1)->andReturn(200);
        $postResponse->shouldReceive('getBody')->times(1)->andReturn(json_encode($accessTokenBody));

        $accountBody = [
            "accounts" => [[
                "id" => "mock_id",
                "business_name" => "mock_business_name",
                "email" => "mock_email",
                "status" => "mock_status"
            ]]
        ];

        $getResponse = m::mock(ResponseInterface::class);
        $getResponse->shouldReceive('getHeader')->times(1)->andReturn('application/json');
        $getResponse->shouldReceive('getStatusCode')->times(1)->andReturn(200);
        $getResponse->shouldReceive('getBody')->times(4)->andReturn(json_encode($accountBody));

        $client = m::mock(ClientInterface::class);
        $client->shouldReceive('setBaseUrl')->times(5);
        $client->shouldReceive('setDefaultOption')->times(4);
        $client->shouldReceive('send')->times(1)->andReturn($postResponse);
        $client->shouldReceive('send')->times(4)->andReturn($getResponse);
        $this->provider->setHttpClient($client);

        $token = $this->provider->getAccessToken('authorization_code', ['code' => 'mock_authorization_code']);
        $user = $this->provider->getResourceOwner($token);

        $this->assertEquals('mock_id', $user->getId());
        $this->assertEquals('mock_business_name', $user->getBusinessName());
        $this->assertEquals('mock_email', $user->getEmail());
        $this->assertEquals('mock_status', $user->getStatus());
        $this->assertTrue(is_array($user->toArray()));
    }

    public function testUserDataFails()
    {
        $errorBodies = [[
            "error" => "mock_error",
            "error_description" => "mock_error_description"
        ], [
            "error" => ["message" => "mock_error"], "error_description" => "mock_error_description"
        ]];

        $testPayload = function ($payload) {
            $postResponse = m::mock(ResponseInterface::class);
            $postResponse->shouldReceive('getBody')->andReturn('{"access_token": "mock_access_token","scopes": "account","expires_in": 3600,"refresh_token": "mock_refresh_token","token_type": "bearer"}');
            $postResponse->shouldReceive('getHeader')->andReturn(['content-type' => 'json']);
            $postResponse->shouldReceive('getStatusCode')->andReturn(200);

            $userResponse = m::mock(ResponseInterface::class);
            $userResponse->shouldReceive('getBody')->andReturn(json_encode($payload));
            $userResponse->shouldReceive('getHeader')->andReturn(['content-type' => 'json']);
            $userResponse->shouldReceive('getStatusCode')->andReturn(500);
            $userResponse->shouldReceive('getReasonPhrase')->andReturn('Internal Server Error');

            $client = m::mock('GuzzleHttp\ClientInterface');
            $client->shouldReceive('send')
                ->times(2)
                ->andReturn($postResponse, $userResponse);
            $this->provider->setHttpClient($client);

            $token = $this->provider->getAccessToken('authorization_code', ['code' => 'mock_authorization_code']);

            try {
                $user = $this->provider->getResourceOwner($token);
                return false;
            } catch (\Exception $e) {
                $this->assertInstanceOf(IdentityProviderException::class, $e);
            }

            return $payload;
        };

        $this->assertCount(2, array_filter(array_map($testPayload, $errorBodies)));
    }
}
