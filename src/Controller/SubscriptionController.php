<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Controller;

use DateTime;
use Exception;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Sylius\Component\Inventory\Checker\AvailabilityCheckerInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Form\SubscriptionType;

final class SubscriptionController extends AbstractController
{
    /** @var RepositoryInterface */
    private $backInStockNotificationRepository;

    /** @var FactoryInterface */
    private $backInStockNotificationFactory;

    /** @var LocaleContextInterface */
    private $localeContext;

    /** @var SenderInterface */
    private $sender;

    /** @var ProductVariantRepositoryInterface */
    private $productVariantRepository;

    /** @var AvailabilityCheckerInterface */
    private $availabilityChecker;

    /** @var CustomerContextInterface */
    private $customerContext;

    /** @var ValidatorInterface */
    private $validator;

    /** @var TranslatorInterface */
    private $translator;

    /** @var ChannelContextInterface */
    private $channelContext;

    public function __construct(
        ChannelContextInterface $channelContext,
        TranslatorInterface $translator,
        ValidatorInterface $validator,
        CustomerContextInterface $customerContext,
        AvailabilityCheckerInterface $availabilityChecker,
        ProductVariantRepositoryInterface $productVariantRepository,
        SenderInterface $sender,
        LocaleContextInterface $localeContext,
        RepositoryInterface $backInStockNotificationRepository,
        FactoryInterface $backInStockNotificationFactory
    ) {
        $this->backInStockNotificationRepository = $backInStockNotificationRepository;
        $this->backInStockNotificationFactory = $backInStockNotificationFactory;
        $this->localeContext = $localeContext;
        $this->sender = $sender;
        $this->productVariantRepository = $productVariantRepository;
        $this->availabilityChecker = $availabilityChecker;
        $this->customerContext = $customerContext;
        $this->validator = $validator;
        $this->translator = $translator;
        $this->channelContext = $channelContext;
    }

    public function addAction(Request $request): Response
    {
        $form = $this->createForm(SubscriptionType::class);
        /** @var string|null $productVariantCode */
        $productVariantCode = $request->query->get('product_variant_code');
        if (is_string($productVariantCode)) {
            $form->setData(['product_variant_code' => $productVariantCode]);
        }

        $customer = $this->customerContext->getCustomer();
        if ($customer !== null && $customer->getEmail() !== null) {
            $form->remove('email');
        }

        $form->handleRequest($request);
        if ($form->isSubmitted() && !$form->isValid()) {
            $this->addFlash('error', $this->translator->trans('webgriffe_bisn.form_submission.invalid_form'));

            return $this->redirect($this->getRefererUrl($request));
        }

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array $data */
            $data = $form->getData();
            /** @var SubscriptionInterface $subscription */
            $subscription = $this->backInStockNotificationFactory->createNew();

            if (array_key_exists('email', $data)) {
                $email = (string) $data['email'];
                $errors = $this->validator->validate($email, [new Email(), new NotBlank()]);
                if (count($errors) > 0) {
                    $this->addFlash('error', $this->translator->trans('webgriffe_bisn.form_submission.invalid_email'));

                    return $this->redirect($this->getRefererUrl($request));
                }
                $subscription->setEmail($email);
            } elseif ($customer !== null) {
                $email = $customer->getEmail();
                if ($email !== null) {
                    $subscription->setCustomer($customer);
                    $subscription->setEmail($email);
                } else {
                    $this->addFlash('error', $this->translator->trans('webgriffe_bisn.form_submission.invalid_form'));

                    return $this->redirect($this->getRefererUrl($request));
                }
            } else {
                $this->addFlash('error', $this->translator->trans('webgriffe_bisn.form_submission.invalid_form'));

                return $this->redirect($this->getRefererUrl($request));
            }

            /** @var ProductVariantInterface|null $variant */
            $variant = $this->productVariantRepository->findOneBy(['code' => $data['product_variant_code']]);
            if (null === $variant) {
                $this->addFlash('error', $this->translator->trans('webgriffe_bisn.form_submission.variant_not_found'));

                return $this->redirect($this->getRefererUrl($request));
            }

            if ($this->availabilityChecker->isStockAvailable($variant) && $variant->isAvailable()) {
                $this->addFlash('error', $this->translator->trans('webgriffe_bisn.form_submission.variant_not_oos'));

                return $this->redirect($this->getRefererUrl($request));
            }

            $subscription->setProductVariant($variant);
            $subscriptionSaved = $this->backInStockNotificationRepository->findOneBy(
                ['email' => $subscription->getEmail(), 'productVariant' => $subscription->getProductVariant()]
            );
            if ($subscriptionSaved) {
                $this->addFlash(
                    'error',
                    $this->translator->trans(
                        'webgriffe_bisn.form_submission.already_saved',
                        ['email' => $subscription->getEmail(),
                        'variant' => $variant->getCode()]
                    )
                );

                return $this->redirect($this->getRefererUrl($request));
            }

            $currentChannel = $this->channelContext->getChannel();
            $subscription->setLocaleCode($this->localeContext->getLocaleCode());
            $subscription->setCreatedAt(new DateTime());
            $subscription->setUpdatedAt(new DateTime());
            $subscription->setChannel($currentChannel);

            try {
                //I generate a random string to handle the delete action of the subscription using a GET
                //This way is easier and does not send sensible information
                //see: https://paragonie.com/blog/2015/09/comprehensive-guide-url-parameter-encryption-in-php
                $hash = strtr(base64_encode(random_bytes(9)), '+/', '-_');
            } catch (Exception $e) {
                $this->addFlash('error', $this->translator->trans('webgriffe_bisn.form_submission.subscription_failed'));

                return $this->redirect($this->getRefererUrl($request));
            }
            $subscription->setHash($hash);

            $this->backInStockNotificationRepository->add($subscription);

            $this->addFlash('success', $this->translator->trans('webgriffe_bisn.form_submission.subscription_successfully'));

            return $this->redirect($this->getRefererUrl($request));
        }

        return $this->render(
            '@WebgriffeSyliusBackInStockNotificationPlugin/productSubscriptionForm.html.twig',
            ['form' => $form->createView()]
        );
    }

    public function deleteAction(Request $request, string $hash): Response
    {
        /** @var SubscriptionInterface|null $subscription */
        $subscription = $this->backInStockNotificationRepository->findOneBy(['hash' => $hash]);
        if (null !== $subscription) {
            $this->backInStockNotificationRepository->remove($subscription);
            $this->addFlash('info', $this->translator->trans('webgriffe_bisn.deletion_submission.successful'));

            return $this->redirect($this->getRefererUrl($request));
        }
        $this->addFlash('info', $this->translator->trans('webgriffe_bisn.deletion_submission.not-successful'));

        return $this->redirect($this->getRefererUrl($request));
    }

    private function getRefererUrl(Request $request): string
    {
        $referer = $request->headers->get('referer');
        if (!is_string($referer)) {
            $referer = $this->generateUrl('sylius_shop_homepage');
        }

        return $referer;
    }
}
