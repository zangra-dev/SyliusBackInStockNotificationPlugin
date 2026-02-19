<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Command;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Inventory\Checker\AvailabilityCheckerInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;
use App\Component\Mailer\MailService;

#[AsCommand(
    name: 'webgriffe:back-in-stock-notification:alert',
    description: 'Sends an email to users who subscribed to back-in-stock notifications when the product is available again.'
)]
final class AlertCommand extends Command
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly SenderInterface $sender,
        private readonly AvailabilityCheckerInterface $availabilityChecker,
        private readonly SubscriptionRepositoryInterface $backInStockNotificationRepository,
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $entityManager,
        private readonly RouterInterface $router,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        //I think that this load in the long time can be a bottle necklace
        /** @var SubscriptionInterface $subscription */
        $subscriptions = $this->backInStockNotificationRepository->findBy([]);

        foreach ($subscriptions as $subscription) {
            $channel = $subscription->getChannel();
            $productVariant = $subscription->getProductVariant();
            if ($productVariant === null || $channel === null) {
                $this->backInStockNotificationRepository->remove($subscription);
                $this->logger->warning(
                    'The back in stock subscription for the product does not have all the information required',
                    ['subscription' => var_export($subscription, true)],
                );

                continue;
            }

            if (
                $this->availabilityChecker->isStockAvailable($productVariant) &&
                $productVariant->isEnabled() &&
                $productVariant->getProduct()?->isEnabled() === true &&
                $productVariant->isAvailable()
            ) {
                $this->router->getContext()->setHost($channel->getHostname() ?? 'localhost');
                $this->sendEmail($subscription, $productVariant, $channel);
                $this->backInStockNotificationRepository->remove($subscription);
            }
        }

        $this->entityManager->flush();

        return Command::SUCCESS;
    }

    private function sendEmail(SubscriptionInterface $subscription, ProductVariantInterface $productVariant, ChannelInterface $channel): void
    {
        $mailer = new MailService($this->mailer, $this->entityManager, null);
        $mailer->sendBackInStockEmail($subscription, $productVariant, $channel);
    }
}
