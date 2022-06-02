<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Command;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Inventory\Checker\AvailabilityCheckerInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;

final class AlertCommand extends Command
{
    protected static $defaultName = 'webgriffe:back-in-stock-notification:alert';

    /** @var RepositoryInterface */
    private $backInStockNotificationRepository;

    /** @var AvailabilityCheckerInterface */
    private $availabilityChecker;

    /** @var SenderInterface */
    private $sender;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @var MailerInterface
     */
    private $mailer;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    public function __construct(
        LoggerInterface $logger,
        SenderInterface $sender,
        AvailabilityCheckerInterface $availabilityChecker,
        RepositoryInterface $backInStockNotificationRepository,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        TranslatorInterface $translator,
        string $name = null
    ) {
        $this->backInStockNotificationRepository = $backInStockNotificationRepository;
        $this->availabilityChecker = $availabilityChecker;
        $this->sender = $sender;
        $this->logger = $logger;
        $this->entityManager = $entityManager;
        $this->mailer = $mailer;
        $this->translator = $translator;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Send an email to the user if the product is returned in stock')
            ->setHelp('Check the stock status of the products in the webgriffe_back_in_stock_notification table and send and email to the user if the product is returned in stock')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        //I think that this load in the long time can be a bottle necklace
        /** @var SubscriptionInterface $subscription */
        $subscriptions = $this->backInStockNotificationRepository->findBy(['notify' => false]);
        foreach ($subscriptions as $subscription) {
            $channel = $subscription->getChannel();
            $productVariant = $subscription->getProductVariant();
            if (null === $productVariant || null === $channel) {
                $this->backInStockNotificationRepository->remove($subscription);
                $this->logger->warning(
                    'The back in stock subscription for the product does not have all the information required',
                    ['subscription' => var_export($subscription, true)]
                );

                continue;
            }

            if ($this->availabilityChecker->isStockAvailable($productVariant)
                && $productVariant->isEnabled()
                && $productVariant->getProduct()->isEnabled()
                && $productVariant->isAvailable()
            ) {
                $this->sendEmail($subscription, $productVariant, $channel);
                $subscription->setNotify(true);
                $this->entityManager->persist($subscription);
                //$this->backInStockNotificationRepository->remove($subscription);
            }
        }

        $this->entityManager->flush();

        return 0;
    }

    private function sendEmail(SubscriptionInterface $subscription, ProductVariantInterface $productVariant, ChannelInterface $channel): void
    {

        $locale = $subscription->getLocaleCode();

        $subject = $this->translator->trans(
            'webgriffe_bisn.alert_email.alert_title',
            [],
            'messages',
            $locale
        );
        $customer = $subscription->getCustomer();
        if (!is_null($customer)) {
            $firstName = $customer->getFirstName();
        }
        else {
            $firstName = '';
        }
        $email = (new TemplatedEmail())
            ->from(new Address('no-reply@zangra.com', 'zangra'))
            ->to(new Address($subscription->getEmail(),$firstName))
            ->bcc(new Address('admin@zangra.com', 'zangra'))
            ->subject('📦 ' . $subject)
            ->htmlTemplate('@SyliusAdmin/Email/backInStock.html.twig')
            ->context([
                'subscription' => $subscription,
                'product' => $productVariant->getProduct(),
                'variant' => $productVariant,
                'channel' => $channel,
                'localeCode' => $subscription->getLocaleCode(),
            ])
        ;

        $this->mailer->send($email);
    }
}
