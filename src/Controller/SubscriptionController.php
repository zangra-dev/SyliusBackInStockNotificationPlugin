<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Controller;

use DateTime;
use Exception;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\CustomerRepositoryInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Sylius\Component\Inventory\Checker\AvailabilityCheckerInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Form\SubscriptionType;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;

final class SubscriptionController extends AbstractController
{
    /**
     * @param FactoryInterface<SubscriptionInterface> $backInStockNotificationFactory
     */
    public function __construct(
        private ChannelContextInterface $channelContext,
        private TranslatorInterface $translator,
        private ValidatorInterface $validator,
        private CustomerContextInterface $customerContext,
        private AvailabilityCheckerInterface $availabilityChecker,
        private ProductVariantRepositoryInterface $productVariantRepository,
        private SenderInterface $sender,
        private LocaleContextInterface $localeContext,
        private SubscriptionRepositoryInterface $backInStockNotificationRepository,
        private FactoryInterface $backInStockNotificationFactory,
        private CustomerRepositoryInterface $customerRepository,
    ) {
    }

    public function addAction(Request $request): Response
    {
        $spam = $request->request->get("customer_email");
        if (!empty($spam)) {
            return $this->redirect($this->getRefererUrl($request));
        }

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
            $subscription = $this->backInStockNotificationFactory->createNew();

            if (array_key_exists('email', $data)) {
                $email = (string) $data['email'];
                $errors = $this->validator->validate($email, [new Email(), new NotBlank()]);
                if (count($errors) > 0) {
                    $this->addFlash('error', $this->translator->trans('webgriffe_bisn.form_submission.invalid_email', ['email' => $email]));

                    return $this->redirect($this->getRefererUrl($request));
                }
                $customer = $this->customerRepository->findOneBy(['email' => $email]);
                if (!is_null($customer)) {
                    $subscription->setCustomer($customer);
                }
                $subscription->setEmail($email);
            } elseif (null !== $customer) {
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
                [
                    'email' => $subscription->getEmail(),
                    'productVariant' => $subscription->getProductVariant(),
                    'notify' => false,
                ],
            );
            if ($subscriptionSaved !== null) {
                if(!$subscriptionSaved->isNotify()) {
                    $this->addFlash(
                        'error',
                        $this->translator->trans(
                            'webgriffe_bisn.form_submission.already_saved',
                            ['email' => $subscription->getEmail(),
                                'variant' => $variant->getCode(),]
                        )
                    );
                } else {
                    $subscriptionSaved->setNotify(false);
                    $this->addFlash('success', $this->translator->trans('webgriffe_bisn.form_submission.subscription_successfully'));
                    $this->backInStockNotificationRepository->add($subscriptionSaved);
                }
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
            } catch (Exception) {
                $this->addFlash(
                    'error',
                    $this->translator->trans('webgriffe_bisn.form_submission.subscription_failed'),
                );

                return $this->redirect($this->getRefererUrl($request));
            }
            $subscription->setHash($hash);

            $this->backInStockNotificationRepository->add($subscription);

            $this->addFlash(
                'success',
                $this->translator->trans('webgriffe_bisn.form_submission.subscription_successfully'),
            );

            return $this->redirect($this->getRefererUrl($request));
        }

        return $this->render(
            '@WebgriffeSyliusBackInStockNotificationPlugin/productSubscriptionForm.html.twig',
            ['form' => $form->createView()],
        );
    }

    public function deleteAction(Request $request, string $hash): Response
    {
        $subscription = $this->backInStockNotificationRepository->findOneBy(['hash' => $hash]);
        if ($subscription === null) {
            $this->addFlash('info', $this->translator->trans('webgriffe_bisn.deletion_submission.not-successful'));

            return $this->redirect($this->getRefererUrl($request));
        }
        $this->backInStockNotificationRepository->remove($subscription);
        $this->addFlash('info', $this->translator->trans('webgriffe_bisn.deletion_submission.successful'));

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
