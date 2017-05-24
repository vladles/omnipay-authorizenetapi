<?php

namespace Omnipay\AuthorizeNetApi\Message;

/**
 *
 */

use Omnipay\Common\Message\AbstractRequest as OmnipayAbstractRequest;
use Academe\AuthorizeNetObjects\Auth\MerchantAuthentication;
use Academe\AuthorizeNetObjects\TransactionRequestInterface;
use Academe\AuthorizeNetObjects\Request\CreateTransaction;

abstract class AbstractRequest extends OmnipayAbstractRequest
{
    /**
     * The live and test gateway endpoints.
     */
    protected $endpointSandbox = 'https://apitest.authorize.net/xml/v1/request.api';
    protected $endpointLive = 'https://apitest.authorize.net/xml/v1/request.api'; // TBC

    /**
     * Get the authentication credentials object.
     */
    public function getAuth()
    {
         return new MerchantAuthentication($this->getAuthName(), $this->getTransactionKey());
    }

    /**
     * Return the relevant endpoint.
     */
    public function getEndpoint()
    {
        if ($this->getTestMode()) {
            return $this->endpointSandbox;
        } else {
            return $this->endpointLive;
        }
    }

    /**
     * Send a HTTP request to the gateway.
     *
     * @return GuzzleHttp\Psr7\Response
     */
    protected function sendRequest($data, $method = 'POST')
    {
        $httpRequest = $this->httpClient->createRequest(
            $method,
            $this->getEndpoint(),
            array(
                'Content-Type' => 'application/json',
            ),
            json_encode($data)
        );

        return $this->httpClient->sendRequest($httpRequest);
    }

    /**
     * Strip a Byte Order Mark (BOM) from the start of a string.
     *
     * @param string $string A string with a potential BOM prefix.
     * @return string The string with the BOM removed.
     */
    public function removeBOM($string)
    {
        return preg_replace('/^[\x00-\x1F\x80-\xFF]{1,3}/', '', $string);
    }

    /**
     * Send a transaction and return the decoded data.
     * TODO: handle unexpected results and HTTP return codes.
     *
     * @return array The decoded data returned by the gateway.
     */
    public function sendTransaction(TransactionRequestInterface $transaction)
    {
        // Wrap the transaction into a request.
        $request =  new CreateTransaction($this->getAuth(), $transaction);

        // Send the request to the gateway.
        $response = $this->sendRequest($request);

        // The caller will know what object to put this data into.
        $body = (string)($response->getBody());

        // The body will be JSON, but *may* have a Byte Order Mark (BOM) prefix.
        // Remove the BOM.
        $body = $this->removeBOM($body);

        // Now decode the JSON body.
        $data = json_decode($body, true);

        // Return a data response.
        return $data;
    }

    /**
     * The application auth name.
     */
    public function setAuthName($value)
    {
        if (!is_string($value)) {
            throw new InvalidRequestException('Auth name must be a string.');
        }

        return $this->setParameter('authName', $value);
    }

    public function getAuthName()
    {
        return $this->getParameter('authName');
    }

    /**
     * The application auth transaction key.
     */
    public function setTransactionKey($value)
    {
        if (!is_string($value)) {
            throw new InvalidRequestException('Transaction Key must be a string.');
        }

        return $this->setParameter('transactionKey', $value);
    }

    public function getTransactionKey()
    {
        return $this->getParameter('transactionKey');
    }

    /**
     * The authorization transaction.
     */
    public function authorize(array $parameters = array())
    {
        return $this->createRequest('\Omnipay\AuthorizeNetApi\Message\AuthorizeRequest', $parameters);
    }
}
