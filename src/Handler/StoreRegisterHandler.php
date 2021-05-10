<?php

declare(strict_types=1);

namespace Odiseo\SyliusMailchimpPlugin\Handler;

use Odiseo\SyliusMailchimpPlugin\Api\EcommerceInterface;
use Odiseo\SyliusMailchimpPlugin\Entity\MailchimpListIdAwareInterface;
use Odiseo\SyliusMailchimpPlugin\Provider\ListIdProviderInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class StoreRegisterHandler implements StoreRegisterHandlerInterface
{
    /**
     * @var EcommerceInterface
     */
    private $ecommerceApi;

    /**
     * @var ListIdProviderInterface
     */
    private $listIdProvider;

    /** @var EventDispatcherInterface */
    private $eventDispatcher;

    /**
     * @var bool
     */
    private $enabled;

    /**
     * @param EcommerceInterface $ecommerceApi
     * @param ListIdProviderInterface $listIdProvider
     * @param EventDispatcherInterface $eventDispatcher
     * @param bool $enabled
     */
    public function __construct(
        EcommerceInterface $ecommerceApi,
        ListIdProviderInterface $listIdProvider,
        EventDispatcherInterface $eventDispatcher,
        bool $enabled
    ) {
        $this->ecommerceApi = $ecommerceApi;
        $this->listIdProvider = $listIdProvider;
        $this->eventDispatcher = $eventDispatcher;
        $this->enabled = $enabled;
    }

    /**
     * {@inheritdoc}
     */
    public function register(ChannelInterface $channel, bool $isSyncing = false)
    {
        if (!$this->enabled) {
            return false;
        }

        $storeId = $channel->getCode();

        $response = $this->ecommerceApi->getStore($storeId);
        $isNew = !isset($response['id']);

        $localeCode = 'en';
        $currencyCode = 'USD';

        if ($defaultLocale = $channel->getDefaultLocale()) {
            $localeCode = $defaultLocale->getCode();
        }

        if ($baseCurrency = $channel->getBaseCurrency()) {
            $currencyCode = $baseCurrency->getCode();
        }

        $data = [
            'id' => $storeId,
            'list_id' => $this->getListIdByChannel($channel),
            'name' => $channel->getName(),
            'platform' => 'Sylius',
            'domain' => $channel->getHostname(),
            'is_syncing' => $isSyncing,
            'email_address' => $channel->getContactEmail(),
            'currency_code' => $currencyCode,
            'primary_locale' => $localeCode,
        ];

        if ($isNew) {
            $event = new GenericEvent($channel, ['data' => $data]);
            $this->eventDispatcher->dispatch($event, 'mailchimp.store.pre_add');
            $data = $event->getArgument('data');

            $response = $this->ecommerceApi->addStore($data);
        } else {
            $event = new GenericEvent($channel, ['data' => $data]);
            $this->eventDispatcher->dispatch($event, 'mailchimp.store.pre_update');
            $data = $event->getArgument('data');

            $response = $this->ecommerceApi->updateStore($storeId, $data);
        }

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function unregister(ChannelInterface $channel)
    {
        if (!$this->enabled) {
            return false;
        }

        $storeId = $channel->getCode();

        $response = $this->ecommerceApi->getStore($storeId);
        $isNew = !isset($response['id']);

        if (!$isNew) {
            $event = new GenericEvent($channel);
            $this->eventDispatcher->dispatch($event, 'mailchimp.store.pre_remove');

            return $this->ecommerceApi->removeStore($storeId);
        }

        return false;
    }

    /**
     * @param ChannelInterface $channel
     *
     * @return string
     */
    private function getListIdByChannel(ChannelInterface $channel): string
    {
        if ($channel instanceof MailchimpListIdAwareInterface) {
            if ($listId = $channel->getListId()) {
                return $listId;
            }
        }

        return $this->listIdProvider->getListId();
    }
}
