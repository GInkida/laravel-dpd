<?php

declare(strict_types=1);

namespace SergeevPasha\DPD\Libraries;

use Exception;
use SoapClient;
use GuzzleHttp\Cookie\CookieJar;
use SergeevPasha\DPD\DTO\Delivery;
use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Message\ResponseInterface;
use SergeevPasha\DPD\Helpers\DPDHelper;

class DPDClient
{
    /**
     * Value to add to the result city ID
     *
     * @var int
     */
    private int $currentMagicValue;

    /**
     * DPD User.
     *
     * @var string
     */
    private string $user;

    /**
     * DPD App key.
     *
     * @var string
     */
    private string $key;

    public function __construct(string $user, string $key)
    {
        $this->user = $user;
        $this->key  = $key;
    }

    /**
     * Authorize a User.
     *
     * @param string|null $login
     * @param string|null $password
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @return string|null
     */
    public function authorize(?string $login = null, ?string $password = null): ?string
    {
        /*
            We need to send a request that will return our DPD Session ID.
            We are not required to send auth data yet
         */
        $response = $this->request('https://www.dpd.ru/ols/order/order.do2', [], null, 'GET');
        $headers  = $response->getHeaders();
        $cookies  = $headers['Set-Cookie'] ?? [];
        $session  = null;
        foreach ($cookies as $cookie) {
            $basicChunks = explode(';', $cookie);
            foreach ($basicChunks as $basicChunk) {
                $generalChunks = explode('=', $basicChunk);
                if ($generalChunks[0] === 'MYDPDSessionID') {
                    $session = $generalChunks[1];
                }
            }
        }
        if ($session) {
            /* That's the tricky part, if we have our session we are now able to log in with our credentials */
            $this->request(
                'https://www.dpd.ru/ols/etc/logon.do2',
                [
                    'username' => $login ?? config('dpd.login'),
                    'password' => $password ?? config('dpd.password'),
                ],
                $session
            );
        }
        return $session;
    }

    /**
     * Find Magic value
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Exception
     */
    private function findMagicValue(): void
    {
        $realCityId    = 48994107;
        $response      = $this->request(
            'https://www.dpd.ru/ols/calc/cities.do2',
            [
                'name_startsWith' => 'Екатеринбург',
                'country'         => '3',
            ],
            null
        );
        $currentCities = json_decode($response->getBody()->getContents(), true);
        if ($currentCities) {
            $this->currentMagicValue = $realCityId - $currentCities['geonames'][0]['id'];
        } else {
            throw new Exception('Failed to connect to DPD Server');
        }
    }

    /**
     * Send request to DPD API.
     *
     * @param string      $path
     * @param array       $params
     * @param string|null $session
     * @param string      $method
     * @param string      $type
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function request(
        string $path,
        array $params,
        ?string $session,
        string $method = 'POST',
        string $type = 'form_params'
    ): ResponseInterface {
        $options = [
            $type         => $params,
            'http_errors' => false,
        ];
        if ($session) {
            $options['cookies'] = CookieJar::fromArray(['MYDPDSessionID' => $session], 'www.dpd.ru');
        } else {
            $options['cookies'] = new CookieJar();
        }
        $client = new GuzzleClient();
        return $client->request($method, $path, $options);
    }


    /**
     * Find a city by query string.
     *
     * @param string $query
     * @param string $country
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @return array|null
     */
    public function findCity(string $query, string $country): ?array
    {
        $this->findMagicValue();
        /* Here we get cities without passing a session ID. That's very important */
        $response = $this->request(
            'https://www.dpd.ru/ols/calc/cities.do2',
            [
                'name_startsWith' => $query,
                'country'         => $country,
            ],
            null
        );
        $data     = json_decode($response->getBody()->getContents(), true);
        /*
            We're going to add magic value to all cities ID, that's the tricky part,
            if we would get cities with Session we would not be able to get
            the true cities ID, as long as they are generated using some of
            its values. Without session, we can just add magic value.
        */
        foreach ($data['geonames'] as $key => $city) {
            $data['geonames'][$key]['id'] = (int) $city['id'] + $this->currentMagicValue;
        }
        return $data;
    }

    /**
     * Find a street by query string and City ID.
     *
     * @param int    $city
     * @param string $query
     * @param string $session
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @return array|null
     */
    public function findCityStreet(int $city, string $query, string $session): ?array
    {
        $response = $this->request(
            'https://www.dpd.ru/ols/order/addressStreetAutocomplete.do2',
            [
                'cityId'     => $city,
                'streetName' => $query,
            ],
            $session
        );
        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Find Receive Point City
     *
     * @param string $query
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @return array
     */
    public function findReceivePointCity(string $query): array
    {
        $answer = $this->request(
            'https://chooser.dpd.ru/api/geocode',
            [
                'value' => $query,
            ],
            null
        );

        return json_decode($answer->getBody()->getContents(), true) ?: [];
    }

    /**
     * Find City Receive Points
     *
     * @param string $bounds
     * @param string $city
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @return array|null
     */
    public function getReceivePoints(string $bounds, string $city): ?array
    {
        $answer = $this->request(
            'https://chooser.dpd.ru/api',
            [
                'bounds' => $bounds,
                'city'   => $city,
            ],
            null,
            'POST',
            'query'
        );
        return json_decode($answer->getBody()->getContents(), true);
    }

    /**
     * Get City Terminals
     *
     * @param string $bounds
     * @param string $city
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @return array|null
     */
    public function getTerminals(string $bounds, string $city): ?array
    {
        $data      = $this->getReceivePoints($bounds, $city);
        $terminals = [];
        if (is_array($data)) {
            $terminals = array_filter(
                $data,
                fn($array) => in_array($array['departmentType'], ['Т', 'СД'])
            );
        }
        return $terminals;
    }

    /**
     * Get Delivery Price
     *
     * @param \SergeevPasha\DPD\DTO\Delivery $delivery
     *
     * @return array|null
     */
    public function getPrice(Delivery $delivery): ?array
    {
        $soap               = new SoapClient('http://ws.dpd.ru/services/calculator2?wsdl');
        $data               = [
            'auth'          => [
                'clientNumber' => $this->user,
                'clientKey'    => $this->key
            ],
            'pickup'        => [
                'cityId' => $delivery->derivalCityId,
            ],
            'delivery'      => [
                'cityId' => $delivery->arrivalCityId,
            ],
            'selfPickup'    => $delivery->derivalTerminal,
            'selfDelivery'  => $delivery->arrivalTerminal,
            'weight'        => $delivery->parcelTotalWeight,
            'volume'        => $delivery->parcelTotalVolume,
            'declaredValue' => $delivery->parcelTotalValue,
            'pickupDate'    => $delivery->pickupDate,
            'maxDays'       => $delivery->maxDeliveryDays,
            'maxPrice'      => $delivery->maxDeliveryPrice,
        ];
        $data               = DPDHelper::removeNullValues($data);
        $request['request'] = $data;
        /* @phpstan-ignore-next-line */
        $result = $soap->getServiceCost2($request);
        return (array) $result;
    }

    /**
     * Find track by number
     *
     * @param string $trackNumber
     *
     * @return array
     */
    public function findByTrackNumber(string $trackNumber): array
    {
        $soap               = new SoapClient('http://ws.dpd.ru/services/tracing1-1?wsdl');
        $data               = [
            'auth'       => [
                'clientNumber' => $this->user,
                'clientKey'    => $this->key
            ],
            'dpdOrderNr' => $trackNumber
        ];
        $request['request'] = $data;
        $states             = $soap->getStatesByDPDOrder($request);

        $result           = end($states->return->states);
        $result->newState = trans("dpd::dpd_statuses.$result->newState");

        return (array) $result;
    }
}
