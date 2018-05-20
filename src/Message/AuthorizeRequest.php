<?php

namespace Omnipay\AuthorizeNetApi\Message;

/**
 * The main authorisation transaction request model.
 */

use Academe\AuthorizeNet\Request\Transaction\AuthOnly;
use Academe\AuthorizeNet\Amount\MoneyPhp;
use Academe\AuthorizeNet\Amount\Amount;
use Academe\AuthorizeNet\Request\Model\NameAddress;
use Academe\AuthorizeNet\Payment\CreditCard;
use Academe\AuthorizeNet\Request\Model\Customer;
use Academe\AuthorizeNet\Request\Model\Retail;
use Academe\AuthorizeNet\Request\Model\Order;
use Academe\AuthorizeNet\AmountInterface;
use Academe\AuthorizeNet\Payment\Track1;
use Academe\AuthorizeNet\Payment\Track2;
use Academe\AuthorizeNet\Request\Collections\LineItems;
use Academe\AuthorizeNet\Request\Model\LineItem;
use Academe\AuthorizeNet\Request\Model\CardholderAuthentication;

use Money\Parser\DecimalMoneyParser;
use Money\Currencies\ISOCurrencies;
use Money\Money;
use Money\Currency;

class AuthorizeRequest extends AbstractRequest
{
    /**
     * Return the complete transaction object which will later be wrapped in
     * a \Academe\AuthorizeNet\Request\CreateTransaction object.
     *
     * @returns \Academe\AuthorizeNet\TransactionRequestInterface
     */
    public function getData()
    {
        $amount = new Amount($this->getCurrency(), $this->getAmountInteger());

        $transaction = $this->createTransaction($amount);

        if ($card = $this->getCard()) {
            $billTo = new NameAddress(
                $card->getBillingFirstName(),
                $card->getBillingLastName(),
                $card->getBillingCompany(),
                trim($card->getBillingAddress1() . ' ' . $card->getBillingAddress2()),
                $card->getBillingCity(),
                $card->getBillingState(),
                $card->getBillingPostcode(),
                $card->getBillingCountry()
            );

            // The billTo may have phone and fax number, but the shipTo does not.
            $billTo = $billTo->withPhoneNumber($card->getBillingPhone());
            $billTo = $billTo->withFaxNumber($card->getBillingFax());

            if ($billTo->hasAny()) {
                $transaction = $transaction->withBillTo($billTo);
            }

            $shipTo = new NameAddress(
                $card->getShippingFirstName(),
                $card->getShippingLastName(),
                $card->getShippingCompany(),
                trim($card->getShippingAddress1() . ' ' . $card->getShippingAddress2()),
                $card->getShippingCity(),
                $card->getShippingState(),
                $card->getShippingPostcode(),
                $card->getShippingCountry()
            );

            if ($shipTo->hasAny()) {
                $transaction = $transaction->withShipTo($shipTo);
            }

            if ($card->getEmail()) {
                // TODO: customer type may be Customer::CUSTOMER_TYPE_INDIVIDUAL or
                // Customer::CUSTOMER_TYPE_BUSINESS and it would be nice to be able
                // to set it.

                $customer = new Customer();
                $customer = $customer->withEmail($card->getEmail());
                $transaction = $transaction->withCustomer($customer);
            }

            // Credit card, track 1 and track 2 are mutually exclusive.

            // A credit card has been supplied.
            if ($card->getNumber()) {
                $card->validate();

                $creditCard = new CreditCard(
                    $card->getNumber(),
                    // Either MMYY or MMYYYY will work.
                    $card->getExpiryMonth() . $card->getExpiryYear()
                );

                if ($card->getCvv()) {
                    $creditCard = $creditCard->withCardCode($card->getCvv());
                }

                $transaction = $transaction->withPayment($creditCard);
            } elseif ($card->getTrack1()) {
                // A card magnetic track has been supplied (aka card present).

                $transaction = $transaction->withPayment(
                    new Track1($card->getTrack1())
                );
            } elseif ($card->getTrack2()) {
                $transaction = $transaction->withPayment(
                    new Track2($card->getTrack2())
                );
            }
        } // credit card

        // TODO: allow "Accept JS" nonce (in two parts) instead of card (aka OpaqueData).

        if ($this->getClientIp()) {
            $transaction = $transaction->withCustomerIp($this->getClientIp());
        }

        // The MarketType and DeviceType is mandatory if tracks are supplied.
        if ($this->getDeviceType() || $this->getMarketType() || (isset($card) && $card->getTracks())) {
            // TODO: accept optional customerSignature
            $retail = new Retail(
                $this->getMarketType() ?: Retail::MARKET_TYPE_RETAIL,
                $this->getDeviceType() ?: Retail::DEVICE_TYPE_UNKNOWN
            );

            $transaction = $transaction->withRetail($retail);
        }

        // The description and invoice number go into an Order object.
        if ($this->getInvoiceNumber() || $this->getDescription()) {
            $order = new Order(
                $this->getInvoiceNumber(),
                $this->getDescription()
            );

            $transaction = $transaction->withOrder($order);
        }

        // 3D Secure is handled by a thirds party provider.
        // These two fields submit the authentication values provided.
        // It is not really clear if both these fields must be always provided together,
        // or whether just one is permitted.
        if ($this->getAuthenticationIndicator() || $this->getAuthenticationValue()) {
            $cardholderAuthentication = new CardholderAuthentication(
                $this->getAuthenticationIndicator(),
                $this->getAuthenticationValue()
            );

            $transaction = $transaction->withCardholderAuthentication($cardholderAuthentication);
        }

        // Is a basket of items to go into the request?
        if ($this->getItems()) {
            $lineItems = new LineItems();

            $currencies = new ISOCurrencies();
            $moneyParser = new DecimalMoneyParser($currencies);

            foreach ($this->getItems() as $itemId => $item) {
                // Parse to a Money object.
                $itemMoney = $moneyParser->parse((string)$item->getPrice(), $this->getCurrency());

                // Omnipay provides the line price, but the LineItem wants the unit price.
                $itemQuantity = $item->getQuantity();

                if (! empty($itemQuantity)) {
                    // Divide the line price by the quantity to get the item price.
                    $itemMoney = $itemMoney->divide($itemQuantity);
                }

                // Wrap in a MoneyPhp object for the AmountInterface.
                $amount = new MoneyPhp($itemMoney);

                $lineItem = new LineItem(
                    $itemId,
                    $item->getName(),
                    $item->getDescription(),
                    $itemQuantity,
                    $amount, // AmountInterface (unit price)
                    null // $taxable
                );

                $lineItems->push($lineItem);
            }

            if ($lineItems->count()) {
                $transaction = $transaction->withLineItems($lineItems);
            }
        }

        $transaction = $transaction->with([
            'terminalNumber' => $this->getTerminalNumber(),
        ]);

        return $transaction;
    }

    /**
     * Create a new instance of the transaction object.
     */
    protected function createTransaction(AmountInterface $amount)
    {
        return new AuthOnly($amount);
    }

    /**
     * Accept a transaction and sends it as a request.
     *
     * @param $data TransactionRequestInterface
     * @returns TransactionResponse
     */
    public function sendData($data)
    {
        $responseData = $this->sendTransaction($data);

        return new AuthorizeResponse($this, $responseData);
    }

    /**
     * TODO: validate values is one of Retail::DEVICE_TYPE_*
     * @param int $value The retail device type.
     * @return $this
     */
    public function setDeviceType($value)
    {
        return $this->setParameter('deviceType', $value);
    }

    /**
     * @return int
     */
    public function getDeviceType()
    {
        return $this->getParameter('deviceType');
    }

    /**
     * TODO: validate values is one of Retail::MARKET_TYPE_*
     * @param int $value The retail market type.
     * @return $this
     */
    public function setMarketType($value)
    {
        return $this->setParameter('marketType', $value);
    }

    /**
     * @return int
     */
    public function getMarketType()
    {
        return $this->getParameter('marketType');
    }
}
