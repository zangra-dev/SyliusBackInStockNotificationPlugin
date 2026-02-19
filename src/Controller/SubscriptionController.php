<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Controller;

if (!interface_exists(\Sylius\Resource\Factory\FactoryInterface::class)) {
    class_alias(\Sylius\Component\Resource\Factory\FactoryInterface::class, \Sylius\Resource\Factory\FactoryInterface::class);
}
use DateTime;
use Sylius\Component\Core\Repository\CustomerRepositoryInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Sylius\Component\Inventory\Checker\AvailabilityCheckerInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Form\SubscriptionType;
use Webgriffe\SyliusBackInStockNotificationPlugin\Repository\SubscriptionRepositoryInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Entity\SubscriptionCreationResult;
use Webmozart\Assert\Assert;

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
        if ($form->isSubmitted()) {
            /** @var array{email?: string, product_variant_code: string} $data */
            $data = $form->getData();
            $result = $this->createSubscriptionFromData($data);

            if ($result->hasErrors()) {
                foreach ($result->errors as $error) {
                    $this->addFlash('error', $error);
                }
            } else {$this->backInStockNotificationRepository->add($result->subscription);
                $this->addFlash(
                    'success',
                    $this->translator->trans('webgriffe_bisn.form_submission.subscription_successfully')
                );
            }

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

    /**
     * @param array{email?: string, product_variant_code: string} $data
     * @return SubscriptionCreationResult
     */
    private function createSubscriptionFromData(array $data): SubscriptionCreationResult
    {
        $customer = $this->customerContext->getCustomer();
        $email = null;
        $subscription = $this->backInStockNotificationFactory->createNew();
        $errors = [];

        if (array_key_exists('email', $data)) {
            $email = (string) $data['email'];
            $violations = $this->validator->validate($email, [new Email(), new NotBlank()]);
            if (count($violations) > 0) {
                $errors[] = $this->translator->trans('webgriffe_bisn.form_submission.invalid_email', ['email' => $email]);
                return new SubscriptionCreationResult(null, $errors);
            }

            $customer = $this->customerRepository->findOneBy(['email' => $email]);
            if ($customer !== null) {
                $subscription->setCustomer($customer);
            }
            $subscription->setEmail($email);
        } elseif ($customer !== null && $customer->getEmail() !== null) {
            $email = $customer->getEmail();
            $subscription->setCustomer($customer);
            $subscription->setEmail($email);
        } else {
            $errors[] = $this->translator->trans('webgriffe_bisn.form_submission.invalid_form');
            return new SubscriptionCreationResult(null, $errors);
        }

        /** @var ProductVariantInterface|null $variant */
        $variant = $this->productVariantRepository->findOneBy(['code' => $data['product_variant_code']]);
        if (null === $variant) {
            $errors[] = $this->translator->trans('webgriffe_bisn.form_submission.variant_not_found');
            return new SubscriptionCreationResult(null, $errors);
        }

        if ($this->availabilityChecker->isStockAvailable($variant) && $variant->isAvailable()) {
            $errors[] = $this->translator->trans('webgriffe_bisn.form_submission.variant_not_oos');
            return new SubscriptionCreationResult(null, $errors);
        }

        $subscription->setProductVariant($variant);

        $existing = $this->backInStockNotificationRepository->findOneBy([
            'email' => $email,
            'productVariant' => $variant,
        ]);

        if ($existing !== null) {
            $errors[] = $this->translator->trans(
                'webgriffe_bisn.form_submission.already_saved',
                ['email' => $email, 'variant' => $variant->getCode()]
            );
            return new SubscriptionCreationResult(null, $errors);
        }

        $subscription->setChannel($this->channelContext->getChannel());
        $subscription->setLocaleCode($this->localeContext->getLocaleCode());
        $subscription->setCreatedAt(new \DateTime());
        $subscription->setUpdatedAt(new \DateTime());

        $hash = strtr(base64_encode(random_bytes(9)), '+/', '-_');
        $subscription->setHash($hash);

        return new SubscriptionCreationResult($subscription);
    }
}
