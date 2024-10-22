<?php

namespace BTCPay\Controller\Admin\Improve\Payment;

use BTCPay;
use BTCPay\Constants;
use BTCPay\Form\Data\General;
use BTCPay\Form\Data\Server;
use BTCPay\Github\Versioning;
use BTCPay\Server\Client;
use BTCPay\Server\Data\ValidateApiKey;
use BTCPayServer\Client\ApiKey;
use Exception;
use PrestaShop\PrestaShop\Core\Domain\Configuration\ShopConfigurationInterface;
use PrestaShop\PrestaShop\Core\Form\FormHandlerInterface;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShopBundle\Security\Annotation\AdminSecurity;
use PrestaShopBundle\Security\Annotation\ModuleActivated;
use PrestaShopLogger;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

if (!\defined('_PS_VERSION_')) {
	exit;
}

/**
 * @ModuleActivated(moduleName="btcpay", redirectRoute="admin_module_manage")
 */
class ConfigureController extends FrameworkBundleAdminController
{
	/**
	 * @var BTCPay
	 */
	private $module;

	/**
	 * @var ValidatorInterface
	 */
	private $validator;

	/**
	 * @var FormHandlerInterface
	 */
	private $serverFormHandler;

	/**
	 * @var FormHandlerInterface
	 */
	private $generalFormHandler;

	/**
	 * @var Versioning
	 */
	private $versioning;

	public function __construct(BTCPay $module, ValidatorInterface $validator, FormHandlerInterface $serverFormHandler, FormHandlerInterface $generalFormHandler)
	{
		parent::__construct();

		$this->module             = $module;
		$this->validator          = $validator;
		$this->serverFormHandler  = $serverFormHandler;
		$this->generalFormHandler = $generalFormHandler;
		$this->versioning         = new Versioning();
	}

	/**
	 * @AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message="Access denied.")
	 *
	 * @throws Exception
	 */
	public function viewAction(Request $request): Response
	{
		// Build the client
		$client = Client::createFromConfiguration($this->getConfiguration());

		// Create the authorization URL (without redirect)
		$authorizeUrl = ApiKey::getAuthorizeUrl($this->getConfiguration()->get(Constants::CONFIGURATION_BTCPAY_HOST), Constants::BTCPAY_PERMISSIONS, $this->module->name, true, true, null, $this->module->name);

		// Ensure we always have a webhook
		if (null !== $client && $client->isValid()) {
			$client->webhook()->ensureWebhook($this->getConfiguration()->get(Constants::CONFIGURATION_BTCPAY_STORE_ID));
		}

		return $this->getResponse($request, $this->serverFormHandler->getForm(), $this->generalFormHandler->getForm(), $authorizeUrl, $client);
	}

	/**
	 * @AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message="Access denied.")
	 *
	 * @return RedirectResponse|Response
	 *
	 * @throws Exception
	 */
	public function editServerSettingsAction(Request $request): Response
	{
		// Get configuration container
		$shopConfiguration = $this->getConfiguration();

		// Get current configuration, before processing everything
		$currentConfiguration = Server::create($shopConfiguration);

		$serverForm = $this->serverFormHandler->getForm();
		$serverForm->handleRequest($request);

		// Just show the boring configuration field on no submit/invalid form
		if (!$serverForm->isSubmitted() || !$serverForm->isValid()) {
			// Try and create the client
			$client = Client::createFromConfiguration($this->getConfiguration());

			// Create the authorization URL (without redirect)
			$authorizeUrl = ApiKey::getAuthorizeUrl($this->getConfiguration()->get(Constants::CONFIGURATION_BTCPAY_HOST), Constants::BTCPAY_PERMISSIONS, $this->module->name, true, true, null, $this->module->name);

			return $this->getResponse($request, $serverForm, $this->generalFormHandler->getForm(), $authorizeUrl, $client);
		}

		/** @var Server $submittedConfiguration */
		$submittedConfiguration = $serverForm->getData();

		// If there are errors in the form, error out here
		if (0 !== \count($saveErrors = $this->serverFormHandler->save($submittedConfiguration->toArray()))) {
			$this->flashErrors($saveErrors);

			return $this->redirectToRoute('admin_btcpay_configure');
		}

		// If the configuration is the same, just stop
		if ($submittedConfiguration->equals($currentConfiguration)) {
			$this->addFlash('success', 'BTCPay Server Plugin: Settings have not changed.');

			return $this->redirectToRoute('admin_btcpay_configure');
		}

		// If we are just removing the API key, do that and return
		if (null === $submittedConfiguration->getApiKey() && !empty($currentConfiguration->getApiKey())) {
			// Remove the current webhook to prevent issues in the future
			if (false === (new Client($shopConfiguration->get(Constants::CONFIGURATION_BTCPAY_HOST), $currentConfiguration->getApiKey()))->webhook()->removeCurrent()) {
				$this->addFlash('error', 'BTCPay Server Plugin: Could not remove webhook from the server. Please double check it is actually gone.');
			}

			$shopConfiguration->set(Constants::CONFIGURATION_BTCPAY_API_KEY, null);
			$shopConfiguration->set(Constants::CONFIGURATION_BTCPAY_STORE_ID, null);
			$shopConfiguration->set(Constants::CONFIGURATION_BTCPAY_WEBHOOK_ID, null);
			$shopConfiguration->set(Constants::CONFIGURATION_BTCPAY_WEBHOOK_SECRET, null);

			$this->addFlash('success', 'BTCPay Server plugin: API key has been removed');

			return $this->redirectToRoute('admin_btcpay_configure');
		}

		// Before processing anything further, make sure any webhook that exists is removed
		(new Client($currentConfiguration->getHost(), $currentConfiguration->getApiKey()))->webhook()->removeCurrent();

		// If an API key is set, use that
		if (null !== $submittedConfiguration->getApiKey()) {
			return $this->processApiKey($submittedConfiguration);
		}

		// If nothing has been set, redirect to the host
		return $this->processRedirect($request, $submittedConfiguration);
	}

	/**
	 * @AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message="Access denied.")
	 *
	 * @return RedirectResponse|Response
	 *
	 * @throws Exception
	 */
	public function editGeneralSettingsAction(Request $request): Response
	{
		// Get current configuration, before processing everything
		$currentConfiguration = General::create($this->getConfiguration());

		$generalForm = $this->generalFormHandler->getForm();
		$generalForm->handleRequest($request);

		// Just show the boring configuration field on no submit/invalid form
		if (!$generalForm->isSubmitted() || !$generalForm->isValid()) {
			// Try and create the client
			$client = Client::createFromConfiguration($this->getConfiguration());

			// Create the authorization URL (without redirect)
			$authorizeUrl = ApiKey::getAuthorizeUrl($this->getConfiguration()->get(Constants::CONFIGURATION_BTCPAY_HOST), Constants::BTCPAY_PERMISSIONS, $this->module->name, true, true, null, $this->module->name);

			return $this->getResponse($request, $this->serverFormHandler->getForm(), $generalForm, $authorizeUrl, $client);
		}

		/** @var General $general */
		$general = $generalForm->getData();

		// If there are errors in the form, error out here
		if (0 !== \count($saveErrors = $this->generalFormHandler->save($general->toArray()))) {
			$this->flashErrors($saveErrors);

			return $this->redirectToRoute('admin_btcpay_configure');
		}

		// If the configuration is the same, just stop
		if ($general->equals($currentConfiguration)) {
			$this->addFlash('success', 'BTCPay Server Plugin: Settings have not changed.');

			return $this->redirectToRoute('admin_btcpay_configure');
		}

		// Return home
		$this->addFlash('success', 'BTCPay Server Plugin: Settings have been saved.');

		return $this->redirectToRoute('admin_btcpay_configure');
	}

	/**
	 * @return RedirectResponse|Response
	 *
	 * @throws Exception
	 */
	public function validateAPIKeyAction(Request $request): Response
	{
		// If we received an empty post we probably hit the PrestaShop security check
		if (empty($request->request->all())) {
			$this->addFlash('error', 'Did not receive data from BTCPay Server. If you received an <strong>Invalid Token</strong> page, make sure to properly setup PrestaShop and BTCPay Server (publicly accessible and HTTPS enabled). Please try again once done or use the API key option.');

			// Make sure to reset the API key
			$this->getConfiguration()->set(Constants::CONFIGURATION_BTCPAY_API_KEY, null);

			return $this->redirectToRoute('admin_btcpay_configure');
		}

		// Validate incoming request and return any errors we encounter
		$validateRequest = new ValidateApiKey($request->request);
		if (0 !== \count($errors = $this->validator->validate($validateRequest))) {
			/** @var ConstraintViolationInterface $error */
			foreach ($errors as $error) {
				$this->addFlash('error', $error->getMessage());
			}

			// Make sure to reset the API key
			$this->getConfiguration()->set(Constants::CONFIGURATION_BTCPAY_API_KEY, null);

			return $this->redirectToRoute('admin_btcpay_configure');
		}

		// Build the client
		$client = new Client($this->getConfiguration()->get(Constants::CONFIGURATION_BTCPAY_HOST), $validateRequest->getApiKey());

		// Get the store ID
		$storeId = $validateRequest->getStoreID();

		try {
			// Ensure we have a valid BTCPay Server version
			if (null !== ($info = $client->server()->getInfo()) && \version_compare($info->getVersion(), Constants::MINIMUM_BTCPAY_VERSION, '<')) {
				$this->addFlash('error', \sprintf('BTCPay server version is too low. Expected %s or higher, received %s.', Constants::MINIMUM_BTCPAY_VERSION, $info->getVersion()));
				PrestaShopLogger::addLog(\sprintf('[ERROR] BTCPay server version is too low. Expected %s or higher, received %s.', Constants::MINIMUM_BTCPAY_VERSION, $info->getVersion()), PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR);

				// Make sure to reset the API key
				$this->getConfiguration()->set(Constants::CONFIGURATION_BTCPAY_API_KEY, null);

				return $this->redirectToRoute('admin_btcpay_configure');
			}

			// Ensure we have a payment methods setup
			if (empty($client->payment()->getPaymentMethods($storeId))) {
				$this->addFlash('error', \sprintf("This plugin expects a payment method to have been setup for store '%s'.", $client->store()->getStore($storeId)->offsetGet('name')));
				PrestaShopLogger::addLog(\sprintf("[ERROR] This plugin expects a payment method to have been setup for store '%s'.", $client->store()->getStore($storeId)->offsetGet('name')), PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR);

				// Make sure to reset the API key
				$this->getConfiguration()->set(Constants::CONFIGURATION_BTCPAY_API_KEY, null);

				return $this->redirectToRoute('admin_btcpay_configure');
			}

			// Ensure we have a webhook
			$client->webhook()->ensureWebhook($storeId);
		} catch (Throwable $throwable) {
			$this->addFlash('error', \sprintf('BTCPay Server plugin: %s', $throwable->getMessage()));
			PrestaShopLogger::addLog(\sprintf('[ERROR] An error occurred during configuration validation: %s', $throwable), PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR, $throwable->getCode());

			return $this->redirectToRoute('admin_btcpay_configure');
		}

		// Store the API key and store ID we received
		$this->getConfiguration()->set(Constants::CONFIGURATION_BTCPAY_API_KEY, $validateRequest->getApiKey());
		$this->getConfiguration()->set(Constants::CONFIGURATION_BTCPAY_STORE_ID, $storeId);

		$this->addFlash('success', 'BTCPay Server plugin: Your store and server have been linked!');

		return $this->redirectToRoute('admin_btcpay_configure');
	}

	protected function getConfiguration(): ShopConfigurationInterface
	{
		// Fallback in case 8.0 is used // TODO: Remove once we make 8.1.0 the minimum
		if (\version_compare(\_PS_VERSION_, '8.1.0', '<')) {
			return $this->configuration;
		}

		return parent::getConfiguration();
	}

	/**
	 * @throws Exception
	 */
	private function processApiKey(Server $configuration): RedirectResponse
	{
		// Get configuration container
		$shopConfiguration = $this->getConfiguration();

		// Build the client
		$client = new Client($configuration->getHost(), $configuration->getApiKey());

		try {
			// Validate created API key and return any errors we encounter
			$validateKey = new ValidateApiKey(new ParameterBag($client->apiKey()->getCurrent()->getData()));
			if (0 !== \count($errors = $this->validator->validate($validateKey))) {
				/** @var ConstraintViolationInterface $error */
				foreach ($errors as $error) {
					$this->addFlash('error', $error->getMessage());
				}

				// Make sure to reset the API key
				$shopConfiguration->set(Constants::CONFIGURATION_BTCPAY_API_KEY, null);

				return $this->redirectToRoute('admin_btcpay_configure');
			}

			// Get the store ID
			$storeId = $validateKey->getStoreID();

			// Grab the store
			$store = $client->store()->getStore($storeId);

			// Ensure we have a valid BTCPay Server version
			if (null !== ($info = $client->server()->getInfo()) && \version_compare($info->getVersion(), Constants::MINIMUM_BTCPAY_VERSION, '<')) {
				$this->addFlash('error', \sprintf('BTCPay server version is too low. Expected %s or higher, received %s.', Constants::MINIMUM_BTCPAY_VERSION, $info->getVersion()));
				PrestaShopLogger::addLog(\sprintf('[ERROR] BTCPay server version is too low. Expected %s or higher, received %s.', Constants::MINIMUM_BTCPAY_VERSION, $info->getVersion()), PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR);

				// Make sure to reset the API key
				$shopConfiguration->set(Constants::CONFIGURATION_BTCPAY_API_KEY, null);

				return $this->redirectToRoute('admin_btcpay_configure');
			}

			// Ensure we have a payment methods setup
			if (empty($client->payment()->getPaymentMethods($storeId))) {
				$this->addFlash('error', \sprintf("This plugin expects a payment method to have been setup for store '%s'.", $store->offsetGet('name')));
				PrestaShopLogger::addLog(\sprintf("[ERROR] This plugin expects a payment method to have been setup for store '%s'.", $store->offsetGet('name')), PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR);

				// Make sure to reset the API key
				$shopConfiguration->set(Constants::CONFIGURATION_BTCPAY_API_KEY, null);

				return $this->redirectToRoute('admin_btcpay_configure');
			}

			// Save the new store ID
			$shopConfiguration->set(Constants::CONFIGURATION_BTCPAY_STORE_ID, $storeId);

			// Ensure we have a webhook
			$client->webhook()->ensureWebhook($storeId);
		} catch (Throwable $throwable) {
			$this->addFlash('error', \sprintf('BTCPay Server plugin: %s', $throwable->getMessage()));
			PrestaShopLogger::addLog(\sprintf('[ERROR] An error occurred during setup: %s', $throwable), PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR, $throwable->getCode());

			// Ensure nothing is saved
			$shopConfiguration->set(Constants::CONFIGURATION_BTCPAY_API_KEY, null);
			$shopConfiguration->set(Constants::CONFIGURATION_BTCPAY_STORE_ID, null);
			$shopConfiguration->set(Constants::CONFIGURATION_BTCPAY_WEBHOOK_ID, null);
			$shopConfiguration->set(Constants::CONFIGURATION_BTCPAY_WEBHOOK_SECRET, null);

			return $this->redirectToRoute('admin_btcpay_configure');
		}

		// Store the API key and store ID we received
		$shopConfiguration->set(Constants::CONFIGURATION_BTCPAY_API_KEY, $validateKey->getApiKey());
		$shopConfiguration->set(Constants::CONFIGURATION_BTCPAY_STORE_ID, $storeId);

		$this->addFlash('success', 'BTCPay Server plugin: Your store and server have been linked!');

		// Return home
		return $this->redirectToRoute('admin_btcpay_configure');
	}

	/**
	 * @throws Exception
	 */
	private function processRedirect(Request $request, Server $configuration): RedirectResponse
	{
		// Get the store name and build the redirect URL
		$storeName   = $this->getContext()->shop->name;
		$redirectUrl = $request->getSchemeAndHttpHost() . $this->getAdminLink('btcpay', ['route' => 'admin_btcpay_validate'], true);

		// Create the authorization URL (with redirect)
		$authorizeUrl = ApiKey::getAuthorizeUrl($configuration->getHost(), Constants::BTCPAY_PERMISSIONS, $storeName, true, true, $redirectUrl, $storeName);

		// If there is no API key, redirect no matter what
		if (empty($apiKey = $this->getConfiguration()->get(Constants::CONFIGURATION_BTCPAY_API_KEY))) {
			return $this->redirect($authorizeUrl);
		}

		// If we have an apiKey, check if it's valid by fetching the storeId
		try {
			$client = new Client($configuration->getHost(), $apiKey);

			// If we don't have a store ID, abort right away
			if (null === ($storeID = $this->getConfiguration()->get(Constants::CONFIGURATION_BTCPAY_STORE_ID))) {
				return $this->redirect($authorizeUrl);
			}

			// Ensure we have a webhook
			$client->webhook()->ensureWebhook($storeID);
		} catch (Throwable) {
			// Reset BTCPay details
			$this->getConfiguration()->set(Constants::CONFIGURATION_BTCPAY_API_KEY, null);
			$this->getConfiguration()->set(Constants::CONFIGURATION_BTCPAY_WEBHOOK_ID, null);
			$this->getConfiguration()->set(Constants::CONFIGURATION_BTCPAY_WEBHOOK_SECRET, null);

			// Redirect away to get proper details
			return $this->redirect($authorizeUrl);
		}

		// Return home
		return $this->redirectToRoute('admin_btcpay_configure');
	}

	private function getResponse(Request $request, FormInterface $serverForm, FormInterface $generalForm, string $authorizeUrl, ?Client $client): Response
	{
		return $this->render('@Modules/btcpay/views/templates/admin/configure.html.twig', [
			'server_form'   => $serverForm->createView(),
			'general_form'  => $generalForm->createView(),
			'help_link'     => $this->generateSidebarLink($request->attributes->get('_legacy_controller')),
			'storeId'       => $this->getConfiguration()->get(Constants::CONFIGURATION_BTCPAY_STORE_ID),
			'webhookId'     => $this->getConfiguration()->get(Constants::CONFIGURATION_BTCPAY_WEBHOOK_ID),
			'latestVersion' => $this->versioning->latest(),
			'moduleVersion' => $this->module->version,
			'authorizeUrl'  => $authorizeUrl,
			'client'        => $client,
			'enableSidebar' => true,
		]);
	}
}
