<?php

declare(strict_types=1);

namespace Odiseo\SyliusMailchimpPlugin\Handler;

use Odiseo\SyliusMailchimpPlugin\Api\EcommerceInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class CustomerRegisterHandler implements CustomerRegisterHandlerInterface
{
    /** @var EcommerceInterface */
    private $ecommerceApi;

    /** @var EventDispatcherInterface */
    private $eventDispatcher;

    /** @var bool */
    private $enabled;

    public function __construct(
        EcommerceInterface $ecommerceApi,
        EventDispatcherInterface $eventDispatcher,
        bool $enabled
    ) {
        $this->ecommerceApi = $ecommerceApi;
        $this->eventDispatcher = $eventDispatcher;
        $this->enabled = $enabled;
    }

    /**
     * {@inheritdoc}
     */
    public function register(
        CustomerInterface $customer,
        ChannelInterface $channel,
        bool $optInStatus = false,
        bool $createOnly = false
    ) {
        if (!$this->enabled) {
            return false;
        }

        $customerId = (string) $customer->getId();
        $storeId = $channel->getCode();
        $customerAddress = $this->getCustomerAddress($customer);
        $firstName = $this->getCustomerFirstName($customer, $customerAddress);
        $lastName = $this->getCustomerLastName($customer, $customerAddress);

        $response = $this->ecommerceApi->getCustomer($storeId, $customerId);
        $isNew = !isset($response['id']);

        // Do nothing if the customer exists
        if (false === $isNew && true === $createOnly) {
            return false;
        }

        $data = [
            'id' => $customerId,
            'email_address' => $customer->getEmail(),
            'opt_in_status' => $optInStatus,
            'first_name' => $firstName ?: '',
            'last_name' => $lastName ?: '',
        ];

        if ($customerAddress) {
            $data['company'] = $customerAddress->getCompany() ?: '';
            $data['address'] = [
                'address1' => $customerAddress->getStreet() ?: '',
                'city' => $customerAddress->getCity() ?: '',
                'province' => $customerAddress->getProvinceName() ?: '',
                'province_code' => $customerAddress->getProvinceCode() ?: '',
                'postal_code' => $customerAddress->getPostcode() ?: '',
                'country_code' => $customerAddress->getCountryCode() ?: '',
            ];
        }

        if ($isNew) {
            $event = new GenericEvent($customer, ['data' => $data, 'channel' => $channel]);
            $this->eventDispatcher->dispatch($event, 'mailchimp.customer.pre_add');
            $data = $event->getArgument('data');

            $response = $this->ecommerceApi->addCustomer($storeId, $data);
        } else {
            $event = new GenericEvent($customer, ['data' => $data, 'channel' => $channel]);
            $this->eventDispatcher->dispatch($event, 'mailchimp.customer.pre_update');
            $data = $event->getArgument('data');

            $response = $this->ecommerceApi->updateCustomer($storeId, $customerId, $data);
        }

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function unregister(CustomerInterface $customer, ChannelInterface $channel)
    {
        if (!$this->enabled) {
            return false;
        }

        $customerId = (string) $customer->getId();
        $storeId = $channel->getCode();

        $response = $this->ecommerceApi->getCustomer($storeId, $customerId);
        $isNew = !isset($response['id']);

        if (!$isNew) {
            $event = new GenericEvent($customer, ['channel' => $channel]);
            $this->eventDispatcher->dispatch($event, 'mailchimp.customer.pre_remove');

            return $this->ecommerceApi->removeCustomer($storeId, $customerId);
        }

        return false;
    }

    /**
     * @param CustomerInterface $customer
     * @return AddressInterface|null
     */
    private function getCustomerAddress(CustomerInterface $customer): ?AddressInterface
    {
        $address = $customer->getDefaultAddress();

        if (!$address && count($customer->getAddresses()) > 0) {
            $address = $customer->getAddresses()->first();
        }

        return $address;
    }

    /**
     * @param CustomerInterface $customer
     * @param AddressInterface|null $address
     * @return string|null
     */
    private function getCustomerFirstName(CustomerInterface $customer, AddressInterface $address = null): ?string
    {
        $firstName = $customer->getFirstName();

        if (!$firstName && $address) {
            $firstName = $address->getFirstName();
        }

        return $firstName;
    }

    /**
     * @param CustomerInterface $customer
     * @param AddressInterface|null $address
     * @return string|null
     */
    private function getCustomerLastName(CustomerInterface $customer, AddressInterface $address = null): ?string
    {
        $lastName = $customer->getLastName();

        if (!$lastName && $address) {
            $lastName = $address->getLastName();
        }

        return $lastName;
    }
}
