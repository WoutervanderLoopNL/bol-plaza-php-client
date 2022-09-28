<?php

namespace Picqer\BolPlazaClient;

use Picqer\BolPlazaClient\Entities\BolPlazaCancellation;
use Picqer\BolPlazaClient\Entities\BolPlazaChangeTransportRequest;
use Picqer\BolPlazaClient\Entities\BolPlazaOfferCreate;
use Picqer\BolPlazaClient\Entities\BolPlazaOfferFile;
use Picqer\BolPlazaClient\Entities\BolPlazaOfferUpdate;
use Picqer\BolPlazaClient\Entities\BolPlazaOrder;
use Picqer\BolPlazaClient\Entities\BolPlazaOrderItem;
use Picqer\BolPlazaClient\Entities\BolPlazaProcessStatus;
use Picqer\BolPlazaClient\Entities\BolPlazaReturnItem;
use Picqer\BolPlazaClient\Entities\BolPlazaReturnItemStatusUpdate;
use Picqer\BolPlazaClient\Entities\BolPlazaShipment;
use Picqer\BolPlazaClient\Entities\BolPlazaShipmentRequest;
use Picqer\BolPlazaClient\Entities\BolPlazaStockUpdate;
use Picqer\BolPlazaClient\Exceptions\BolPlazaClientException;
use Picqer\BolPlazaClient\Exceptions\BolPlazaClientRateLimitException;
use Picqer\BolPlazaClient\Request\CurlHttpRequest;

class BolPlazaClient
{
    const URL_LIVE = 'https://vd.nl';
    const URL_TEST = 'https://test-plazaapi.bol.com';
    const API_VERSION = 'v2';
    const OFFER_API_VERSION = 'v1';

    private $testMode = false;
    private $skipSslVerification = false;

    private $publicKey;
    private $privateKey;

    /**
     * BolPlazaClient constructor.
     * @param $publicKey
     * @param $privateKey
     */
    public function __construct($publicKey, $privateKey)
    {
        $this->publicKey = $publicKey;
        $this->privateKey = $privateKey;
    }

    /**
     * Enable or disable testmode (default disabled)
     * @param $mode boolean
     */
    public function setTestMode($mode)
    {
        $this->testMode = $mode;
    }

    /**
     * Skip SSL verification in communication with server, only use in test cases
     * @param bool|true $mode
     */
    public function setSkipSslVerification($mode = true)
    {
        $this->skipSslVerification = $mode;
    }

    /**
     * Get list of orders
     * @param int $page
     * @param string $fulfilmentMethod
     * @return array
     * @throws BolPlazaClientException
     * @throws BolPlazaClientRateLimitException
     */
    public function getOrders($page = 1, $fulfilmentMethod = 'FBR')
    {
        $parameters = [
            'page' => $page,
            'fulfilment-method' => $fulfilmentMethod
        ];

        $url = '/api/orders/';

        $apiResult = $this->makeRequest('GET', $url, $parameters, ['Accept: application/xml']);

        $orders = BolPlazaDataParser::createCollectionFromResponse('BolPlazaOrder', $apiResult);

        return $orders;
    }

    /**
     * Get single order
     * @param string $orderId
     * @return BolPlazaOrder|null
     * @throws BolPlazaClientException
     * @throws BolPlazaClientRateLimitException
     */
    public function getOrder($orderId)
    {
        $url = sprintf('/api/orders/%s', $orderId);

        $apiResult = $this->makeRequest('GET', $url, null, ['Accept: application/xml']);

        $order = BolPlazaDataParser::createCollectionFromResponse('BolPlazaOrder', $apiResult);

        return isset($order[0]) ? $order[0] : null;
    }

    /**
     * Get list of shipments
     * @param int $page The page of the set of shipments
     * @param string $fulfilmentMethod
     * @param string|null $orderId
     * @return array
     * @throws BolPlazaClientException
     * @throws BolPlazaClientRateLimitException
     */
    public function getShipments($page = 1, $fulfilmentMethod = 'FBR', $orderId = null)
    {
        $parameters = [
            'page' => $page,
            'fulfilment-method' => $fulfilmentMethod,
        ];

        if ($orderId) {
            $parameters['order-id'] = $orderId;
        }

        $url = '/services/rest/shipments/' . self::API_VERSION;
        $apiResult = $this->makeRequest('GET', $url, $parameters, ['Accept: application/vnd.shipments-v2.1+xml']);
        $shipments = BolPlazaDataParser::createCollectionFromResponse('BolPlazaShipment', $apiResult);
        return $shipments;
    }

    /**
     * Get list of BolPlazaReturnItem entities
     * @return array
     */
    public function getReturnItems()
    {
        $url = '/services/rest/return-items/' . self::API_VERSION . '/unhandled';
        $apiResult = $this->makeRequest('GET', $url);
        $returnItems = BolPlazaDataParser::createCollectionFromResponse('BolPlazaReturnItem', $apiResult);
        return $returnItems;
    }

    /**
     * Get list of BolPlazaPayment entities
     * @return array
     */
    public function getPayments($period)
    {
        $url = '/services/rest/payments/' . self::API_VERSION . '/' . $period;
        $apiResult = $this->makeRequest('GET', $url);
        $payments = BolPlazaDataParser::createCollectionFromResponse('BolPlazaPayment', $apiResult);
        return $payments;
    }

    /**
     * Handle a BolPlazaReturnItem
     * @param BolPlazaReturnItem $returnItem
     * @param BolPlazaReturnItemStatusUpdate $status
     * @return BolPlazaProcessStatus
     */
    public function handleReturnItem(Entities\BolPlazaReturnItem $returnItem, Entities\BolPlazaReturnItemStatusUpdate $status)
    {
        $url = '/services/rest/return-items/' . self::API_VERSION . '/' . $returnItem->ReturnNumber . '/handle';
        $xmlData = BolPlazaDataParser::createXmlFromEntity($status);
        $apiResult = $this->makeRequest('PUT', $url, $xmlData);
        $result = BolPlazaDataParser::createEntityFromResponse('BolPlazaProcessStatus', $apiResult);
        return $result;
    }

    /**
     * Cancel an OrderItem
     * @param BolPlazaOrderItem $orderItem
     * @param BolPlazaCancellation $cancellation
     * @return BolPlazaProcessStatus
     */
    public function cancelOrderItem(Entities\BolPlazaOrderItem $orderItem, Entities\BolPlazaCancellation $cancellation)
    {
        $url = '/services/rest/order-items/' . self::API_VERSION . '/' . $orderItem->OrderItemId . '/cancellation';
        $xmlData = BolPlazaDataParser::createXmlFromEntity($cancellation);
        $apiResult = $this->makeRequest('PUT', $url, $xmlData);
        $result = BolPlazaDataParser::createEntityFromResponse('BolPlazaProcessStatus', $apiResult);
        return $result;
    }

    /**
     * Change Transport
     * @param BolPlazaShipment $shipment
     * @param BolPlazaChangeTransportRequest $changeRequest
     * @return BolPlazaProcessStatus
     */
    public function changeTransport(Entities\BolPlazaShipment $shipment, Entities\BolPlazaChangeTransportRequest $changeRequest)
    {
        $url = '/services/rest/transports/' . self::API_VERSION . '/' . $shipment->Transport->TransportId;
        $xmlData = BolPlazaDataParser::createXmlFromEntity($changeRequest);
        $apiResult = $this->makeRequest('PUT', $url, $xmlData);
        $result = BolPlazaDataParser::createEntityFromResponse('BolPlazaProcessStatus', $apiResult);
        return $result;
    }

    /**
     * Add a shipment
     * @param BolPlazaShipmentRequest $shipmentRequest
     * @return BolPlazaProcessStatus
     */
    public function processShipment(Entities\BolPlazaShipmentRequest $shipmentRequest)
    {
        $url = '/api/shipments/';
        $xmlData = BolPlazaDataParser::createXmlFromEntity($shipmentRequest, 'v2.1');
        $apiResult = $this->makeRequest('POST', $url, $xmlData, ['Accept: application/xml']);
        $result = BolPlazaDataParser::createEntityFromResponse('BolPlazaProcessStatus', $apiResult);
        return $result;
    }

    /**
     * Get the ProcessStatus
     * @param string $processStatusId
     * @return BolPlazaProcessStatus
     */
    public function getProcessStatus($processStatusId)
    {
        $url = '/services/rest/process-status/' . self::API_VERSION . '/' . $processStatusId;
        $apiResult = $this->makeRequest('GET', $url);
        $result = BolPlazaDataParser::createEntityFromResponse('BolPlazaProcessStatus', $apiResult);
        return $result;
    }

    /**
     * Create an offer
     * @param string $offerId
     * @param BolPlazaOfferCreate $offerCreate
     * @return
     */
    public function createOffer($offerId, Entities\BolPlazaOfferCreate $offerCreate)
    {
        $url = '/offers/' . self::OFFER_API_VERSION . '/' . $offerId;
        $xmlData = BolPlazaDataParser::createOfferXmlFromEntity($offerCreate);
        $apiResult = $this->makeRequest('POST', $url, $xmlData);
        return $apiResult;
    }

    /**
     * Update an offer
     * @param string $offerId
     * @param BolPlazaOfferUpdate $offerUpdate
     * @return
     */
    public function updateOffer($offerId, Entities\BolPlazaOfferUpdate $offerUpdate)
    {
        $url = '/offers/' . self::OFFER_API_VERSION . '/' . $offerId;
        $xmlData = BolPlazaDataParser::createOfferXmlFromEntity($offerUpdate);
        $apiResult = $this->makeRequest('PUT', $url, $xmlData);
        return $apiResult;
    }

    /**
     * Update an offer stock
     * @param string $offerId
     * @param BolPlazaStockUpdate $stockUpdate
     * @return
     */
    public function updateOfferStock($offerId, Entities\BolPlazaStockUpdate $stockUpdate)
    {
        $url = '/offers/' . self::OFFER_API_VERSION . '/' . $offerId . '/stock';
        $xmlData = BolPlazaDataParser::createOfferXmlFromEntity($stockUpdate);
        $apiResult = $this->makeRequest('PUT', $url, $xmlData);
        return $apiResult;
    }

    /**
     * Delete an offer
     * @param string $offerId
     * @return
     */
    public function deleteOffer($offerId)
    {
        $url = '/offers/' . self::OFFER_API_VERSION . '/' . $offerId;
        $apiResult = $this->makeRequest('DELETE', $url);
        return $apiResult;
    }

    /**
     * Get own offers file path
     * @param string $filter
     * @return BolPlazaOfferFile
     */
    public function getOwnOffers($filter = '')
    {
        $url = '/offers/' . self::OFFER_API_VERSION . '/export';
        $data = [];
        if(!empty($filter)) {
            $data['filter'] = $filter;
        }
        $apiResult = $this->makeRequest('GET', $url, $data);
        $result = BolPlazaDataParser::createEntityFromResponse('BolPlazaOfferFile', $apiResult);
        return $result;
    }

    /**
     * Get the own offers file contents
     * @param string $path
     * @return string
     */
    public function getOwnOffersResult($path = '')
    {
        $path = str_replace(self::URL_TEST, '', $path);
        $path = str_replace(self::URL_LIVE, '', $path);
        $apiResult = $this->makeRequest('GET', $path);
        return $apiResult;
    }

    /**
     * Makes the request to the server and processes errors
     *
     * @param string $method GET
     * @param string $endpoint URI of the resource
     * @param null|string $data POST data
     * @param array $additionalHeaders additional headers (optional)
     * @return string XML
     * @throws BolPlazaClientException
     * @throws BolPlazaClientRateLimitException
     */
    protected function makeRequest($method = 'GET', $endpoint, $data = null, $additionalHeaders = [])
    {
        $date = gmdate('D, d M Y H:i:s T');
        $contentType = 'application/xml';
        $url = $this->getUrlFromEndpoint($endpoint);

        $signature = $this->getSignature($method, $contentType, $date, $endpoint);

        $headers = array_merge($additionalHeaders, [
            'Content-type: ' . $contentType,
            'X-BOL-Date: ' . $date,
            'X-BOL-Authorization: ' . $signature
        ]);

        $httpRequest = $this->createHttpRequest($url);

        $httpRequest->setOption(CURLOPT_CUSTOMREQUEST, $method);
        $httpRequest->setOption(CURLOPT_RETURNTRANSFER, true);
        $httpRequest->setOption(CURLOPT_TIMEOUT, 60);
        $httpRequest->setOption(CURLOPT_HEADER, false);
        $httpRequest->setOption(CURLOPT_USERAGENT, 'Picqer BolPlaza PHP Client (picqer.com)');
        $httpRequest->setOption(CURLOPT_HTTPHEADER, $headers);

        if (in_array($method, ['POST', 'PUT', 'DELETE']) && ! is_null($data)) {
            $httpRequest->setOption(CURLOPT_POSTFIELDS, $data);
        } elseif ($method == 'GET' && !empty($data)) {
            $httpRequest->setOption(CURLOPT_URL, $url . '?' . http_build_query($data));
        }

        if ($this->skipSslVerification) {
            $httpRequest->setOption(CURLOPT_SSL_VERIFYPEER, false);
            $httpRequest->setOption(CURLOPT_SSL_VERIFYHOST, false);
        }

        $result = $httpRequest->execute();
        $headerInfo = $httpRequest->getInfo();

        $this->checkForErrors($httpRequest, $headerInfo, $result);

        $httpRequest->close();

        return $result;
    }

    /**
     * Get URL from endpoint
     *
     * @param string $endpoint
     * @return string
     */
    protected function getUrlFromEndpoint($endpoint)
    {
        if ($this->testMode) {
            return self::URL_TEST . $endpoint;
        } else {
            return self::URL_LIVE . $endpoint;
        }
    }

    /**
     * Calculates signature for request
     *
     * @param string $method HTTP method
     * @param string $contentType Probably only application/xml
     * @param string $date Current time (can only be 15 mins apart from Bol servers)
     * @param string $endpoint Endpoint without url
     * @return string
     */
    protected function getSignature($method, $contentType, $date, $endpoint)
    {
        $signatureBase = $method . "\n\n";
        $signatureBase .= $contentType . "\n";
        $signatureBase .= $date . "\n";
        $signatureBase .= 'x-bol-date:' . $date . "\n";
        $signatureBase .= $endpoint;

        $signature = $this->publicKey . ':' . base64_encode(hash_hmac('SHA256', $signatureBase, $this->privateKey, true));

        return $signature;
    }

    /**
     * Check if the API returned any errors
     *
     * @param CurlHttpRequest $httpRequest
     * @param array $headerInfo
     * @param string $result
     * @throws BolPlazaClientException
     * @throws BolPlazaClientRateLimitException
     */
    protected function checkForErrors(CurlHttpRequest $httpRequest, $headerInfo, $result)
    {
        if ($httpRequest->getErrorNumber()) {
            throw new BolPlazaClientException($httpRequest->getErrorNumber());
        }

        if (! in_array($headerInfo['http_code'], array('200', '201', '204'))) // API returns error
        {
            if ($headerInfo['http_code'] == '409') {
                throw new BolPlazaClientRateLimitException;
            }

            if (! empty($result)) {
                $this->extractExceptionFromResponse($result);
            }
        }
    }

    /**
     * @param string $result
     * @throws BolPlazaClientException
     */
    protected function extractExceptionFromResponse($result)
    {
        // Documentation does not show namespaces but (error)response contains "bns" namespace, try both to stay backwards compatible
        $namespaces = array('', 'bns');
        foreach($namespaces as $namespace) {
            $xmlObject = BolPlazaDataParser::parseXmlResponse($result, $namespace);
            if (isset($xmlObject->ErrorCode) && ! empty($xmlObject->ErrorCode)) {
                throw new BolPlazaClientException($xmlObject->ErrorMessage, (int)$xmlObject->ErrorCode);
            }
        }
    }

    /**
     * @param string $url
     * @return CurlHttpRequest
     */
    protected function createHttpRequest($url)
    {
        return new CurlHttpRequest($url);
    }
}
