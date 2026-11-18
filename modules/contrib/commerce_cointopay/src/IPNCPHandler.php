<?php

namespace Drupal\commerce_cointopay;

use Drupal\commerce_order\Entity\Order;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Schema\ArrayElement;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class IPNCPHandler implements IPNCPHandlerInterface
{
	use StringTranslationTrait;

    /**
     * The database connection to use.
     *
     * @var \Drupal\Core\Database\Connection
     */
    protected $connection;

    /**
     * The entity type manager.
     *
     * @var \Drupal\Core\Entity\EntityTypeManagerInterface
     */
    protected $entityTypeManager;

    /**
     * The logger.
     *
     * @var \Drupal\Core\Logger\LoggerChannelInterface
     */
    protected $logger;

    /**
     * The HTTP client.
     *
     * @var \GuzzleHttp\Client
     */
    protected $httpClient;

    /**
     * The config object for 'commerce_payment.commerce_payment_gateway.coin_topay'.
     *
     * @var \Drupal\Core\Config\ConfigFactoryInterface
     */
    private $commerceCointopay;

    /**
     * Constructs a new PaymentGatewayBase object.
     *
     * @param \Drupal\Core\Database\Connection $connection
     *   The database connection to use.
     * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
     *   The entity type manager.
     * @param \Psr\Log\LoggerInterface $logger
     *   The logger channel.
     * @param \GuzzleHttp\ClientInterface $client
     *   The client.
     * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
     *   Config object.
     */
    public function __construct(Connection $connection, EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger, ClientInterface $client, ConfigFactoryInterface $configFactory)
    {
        $this->connection = $connection;
        $this->entityTypeManager = $entity_type_manager;
        $this->logger = $logger;
        $this->httpClient = $client;
        $this->commerceCointopay = $configFactory->get('commerce_payment.commerce_payment_gateway.cointopay');
    }

    /**
     * {@inheritdoc}
     */
    public function process(Request $request)
    {
        $response_data = [
            'order_id' => $request->get('CustomerReferenceNr'),
            'transaction_id' => $request->get('TransactionID'),
            'status' => $request->get('status'),
            'not_enough' => $request->get('notenough'),
            'confirm_code' => $request->get('ConfirmCode')
        ];
		// Check the post data sent is valid or not.
		$transactionData = $this->getTransactiondetail($response_data);
		if($transactionData['status_code'] != 200){
			echo $this->notifyPage(
						'Order update failed',
						$transactionData['message'],
						500
					);
					exit;
		}
		else{
			if($transactionData['data']['Security'] != $request->get('ConfirmCode')){
				echo $this->notifyPage(
						'Order update failed',
						'Data mismatch! ConfirmCode doesn\'t match.',
						500
					);
					exit;
			}
			elseif($transactionData['data']['CustomerReferenceNr'] != $request->get('CustomerReferenceNr')){
				echo $this->notifyPage(
						'Order update failed',
						'Data mismatch! CustomerReferenceNr doesn\'t match.',
						500
					);
					exit;
			}
			elseif($transactionData['data']['TransactionID'] != $request->get('TransactionID')){
				echo $this->notifyPage(
						'Order update failed',
						'Data mismatch! TransactionID doesn\'t match.',
						500
					);
					exit;
			}
			elseif($request->get('AltCoinID') != null && $transactionData['data']['AltCoinID'] != $request->get('AltCoinID')){
				echo $this->notifyPage(
						'Order update failed',
						'Data mismatch! AltCoinID doesn\'t match.',
						500
					);
					exit;
			}
			elseif($request->get('MerchantID') != null && $transactionData['data']['MerchantID'] != $request->get('MerchantID')){
				echo $this->notifyPage(
						'Order update failed',
						'Data mismatch! MerchantID doesn\'t match.',
						500
					);
					exit;
			}
			elseif($request->get('CoinAddressUsed') != null && $transactionData['data']['coinAddress'] != $request->get('CoinAddressUsed')){
				echo $this->notifyPage(
						'Order update failed',
						'Data mismatch! coinAddress doesn\'t match.',
						500
					);
					exit;
			}
			elseif($request->get('SecurityCode') != null && $transactionData['data']['SecurityCode'] != $request->get('SecurityCode')){
				echo $this->notifyPage(
						'Order update failed',
						'Data mismatch! SecurityCode doesn\'t match.',
						500
					);
					exit;
			}
			elseif($request->get('inputCurrency') != null && $transactionData['data']['inputCurrency'] != $request->get('inputCurrency')){
				echo $this->notifyPage(
						'Order update failed',
						'Data mismatch! inputCurrency doesn\'t match.',
						500
					);
					exit;
			}
			elseif (
						 $transactionData['data']['Status'] !== $request->get('status')
						&& empty($request->get('cancel'))
						&& !(
							strtolower($request->get('status')) === 'paid'
							&& !empty($request->get('notenough'))
							&& $request->get('notenough') == 1
						)
						&& !(
							strtolower( $transactionData['data']['Status']) === 'expired'
							&& strtolower($request->get('status')) === 'failed'
						)
					) {
					echo $this->notifyPage(
						'Order update failed',
						'Data mismatch! status doesn\'t match. Your order status is '.$transactionData['data']['Status'],
						500
					);
					exit;
			}
			
		}
        // Check the post data sent is valid or not.
        if (!$this->commerce_cointopay_is_response_valid($response_data)) {
            return FALSE;
        }

        $storage = $this->entityTypeManager->getStorage('commerce_payment');
        $order = empty($response_data['order_id']) ? false : $this->commerce_cointopay_order_load($response_data['order_id']);

        if ($order != FALSE) {
            // Create a new payment transaction for the order.
            $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
            $commerece_log = $this->entityTypeManager->getStorage('commerce_log');

            $transaction_array = $storage->loadByProperties(['remote_id' => $response_data['transaction_id']]);
			$transaction = array_shift($transaction_array);

			if (!$transaction) {
			  $transaction = $payment_storage->create([
				'state' => 'new',
				'amount' => $order->getTotalPrice(),
				'payment_gateway' => $order->get('payment_gateway')->getString(),
				'order_id' => $order->id(),
				'remote_id' => $response_data['transaction_id'],
			  ]);
			  $transaction->setState('new');
			}

            

            
			
			$current_state = $order->getState()->getId();

			if ($response_data['status'] == 'paid') {
			  $not_enough = !empty($request->get('notenough'));

			  // Already finished (duplicate IPN / refresh): nothing to do.
			  if ($current_state === 'completed' && !$not_enough) {
				echo $this->notifyPage('Payment completed', 'Order is processing.', 200, true); exit;
			  }

			  // 'place' only applies from draft; skip silently if already placed.
			  $this->applyOrderTransition($order, 'place');
			  $transaction->setRemoteState('place');
			  $transaction->setState('place');

			  if (!$not_enough) {
				if (!$this->applyOrderTransition($order, 'fulfill')) {
				  $this->logger->warning('Fulfill not allowed for Order @id in state @state.', [
					'@id' => $order->id(), '@state' => $order->getState()->getId(),
				  ]);
				  echo $this->notifyPage('Order update failed', 'Order cannot be completed from its current state.', 200, false, 'warning'); exit;
				}
				$transaction->setRemoteState('completed');
				$transaction->setState('completed');
				$commerece_log->generate($order, 'ctp_order_completed')->save();
				$this->saveTransaction($transaction, $order, $response_data);
				echo $this->notifyPage('Order update', 'Payment Successful.', 200, true); exit;
			  }

			  if ($current_state === 'validation') {
				echo $this->notifyPage('Order is processing', 'Order is awaiting validation.', 200, false, 'warning'); exit;
			  }
			  if (!$this->applyOrderTransition($order, 'validate')) {
				echo $this->notifyPage('Order update failed', 'Order cannot be validated from its current state.', 200, false, 'warning'); exit;
			  }
			  $transaction->setRemoteState('validation');
			  $transaction->setState('validation');
			  $commerece_log->generate($order, 'not_enough')->save();
			  $this->saveTransaction($transaction, $order, $response_data);
			  echo $this->notifyPage('Order update failed', 'Payment failed notification from Cointopay because not enough.', 500, false, 'warning'); exit;
			}

			// Non-paid statuses: map to transition, remote state, log key, messages.
			if (!empty($request->get('cancel')) || !in_array($response_data['status'], ['failed', 'expired'], TRUE)) {
			  $map = ['transition' => 'cancel', 'state' => 'canceled', 'log' => 'ctp_order_canceled',
					  'title' => 'Order update cancel', 'msg' => 'Payment Cancelled notification from Cointopay'];
			}
			elseif ($response_data['status'] == 'failed') {
			  $map = ['transition' => 'fail', 'state' => 'failed', 'log' => 'ctp_order_failed',
					  'title' => 'Order update failed', 'msg' => 'Payment Failed notification from Cointopay'];
			}
			else {
			  $map = ['transition' => 'expire', 'state' => 'expired', 'log' => 'ctp_order_expired',
					  'title' => 'Order update expired', 'msg' => 'Payment Expired notification from Cointopay'];
			}

			// Already in the target state (refresh / duplicate IPN): respond OK, change nothing.
			if ($current_state === $map['state']) {
			  echo $this->notifyPage($map['title'], 'This order is ' . $map['state'] . '.', 200, false, 'warning'); exit;
			}

			// Don't cancel/fail/expire an order that was already paid and completed.
			if ($current_state === 'completed') {
			  $this->logger->warning('Ignored @status IPN for completed Order @id.', [
				'@status' => $map['state'], '@id' => $order->id(),
			  ]);
			  echo $this->notifyPage('Payment completed', 'Order is processed.', 200, true); exit;
			}

			if (!$this->applyOrderTransition($order, $map['transition'])) {
			  $this->logger->warning('Transition @t not allowed for Order @id in state @state.', [
				'@t' => $map['transition'], '@id' => $order->id(), '@state' => $current_state,
			  ]);
			  echo $this->notifyPage($map['title'], 'Order cannot be updated from its current state (' . $current_state . ').', 200, false, 'warning'); exit;
			}

			$transaction->setRemoteState($map['state']);
			$transaction->setState($map['state']);
			$commerece_log->generate($order, $map['log'])->save();
			$this->saveTransaction($transaction, $order, $response_data);
			echo $this->notifyPage($map['title'], $map['msg'], 500, false, 'warning');exit;
            
        } else {
            $this->logger->notice('Could not find order for TxnID: @txn_id, ignored.', ['@txn_id' => $response_data['transaction_id']]);
            echo $this->notifyPage('Order failed', 'Could not find order for TxnID:' . $response_data['transaction_id'] . ', ignored.', 500, false, 'warning'); exit;
        }
		echo $this->notifyPage('Somthing Wrong', 'Something went wrong, try again later.', 500, false, 'warning'); exit;
    }

    /**
     * @param $data
     * @return bool
     */
    protected function commerce_cointopay_is_response_valid($data)
    {
        $config = \Drupal::config('commerce_cointopay.commerce_payment_gateway.plugin.cointopay_redirect');
        $config = $config->getStorage()->read('commerce_payment.commerce_payment_gateway.cointopay');
        $config = $config['configuration'];

        if (empty($data['order_id'])) {
            $this->logger->alert('Customer Reference Number received is incorrect.');
            throw new BadRequestHttpException('Customer Reference Number received is incorrect.');
            return FALSE;
        }
        if (empty($data['transaction_id'])) {
            $this->logger->alert('Transaction id received is incorrect.');
            throw new BadRequestHttpException('Transaction id received is incorrect.');
            return FALSE;
        }
        if (empty($data['status'])) {
            $this->logger->alert('Status of transaction received is incorrect.');
            throw new BadRequestHttpException('Status of transaction received is incorrect.');
            return FALSE;
        }

        $url = "https://app.cointopay.com/v2REAPI?MerchantID={$config['merchant_id']}&Call=QA&APIKey=_&output=json&TransactionID={$data['transaction_id']}&ConfirmCode={$data['confirm_code']}";
        $curl = curl_init($url);
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => 0
        ));
        $result = curl_exec($curl);
        $result = json_decode($result, true);
        $return = true;
        if(!$result || !is_array($result)) {
            $this->logger->alert('Deprecated data ! Your data do not match to Cointopay.');
            throw new BadRequestHttpException('Deprecated data ! Your data do not match to Cointopay.');
            $return =  false;
        }
		
        return $return;
    }
	protected function getTransactiondetail($data) {
        $return = true;
		$config = \Drupal::config('commerce_cointopay.commerce_payment_gateway.plugin.cointopay_redirect');
        $config = $config->getStorage()->read('commerce_payment.commerce_payment_gateway.cointopay');
        $config = $config['configuration'];

        if (empty($data['confirm_code'])) {
            $this->logger->alert('Confirm Coder received is incorrect.');
            throw new BadRequestHttpException('Confirm Coder received is incorrect.');
            return FALSE;
        }

        $url = "https://cointopay.com/v2REAPI?Call=Transactiondetail&MerchantID={$config['merchant_id']}&output=json&ConfirmCode={$data['confirm_code']}&APIKey=a";
        $curl = curl_init($url);
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => 0
        ));
        $result = curl_exec($curl);
        $results = json_decode($result, true);
        return $results;
    }


    /**
     * Loads the commerce order.
     *
     * @param $order_id
     *   The order ID.
     *
     * @return object
     *   The commerce order object.
     */
    protected function commerce_cointopay_order_load($order_id)
    {
        $order = Order::load($order_id);
        return $order ? $order : FALSE;
    }
	
	/**
	 * TRUE when the request comes from a customer's browser, not Cointopay's server.
	 */
	protected function isBrowserRequest(): bool {
	  $request = \Drupal::request();
	  return str_contains((string) $request->headers->get('Accept'), 'text/html')
		&& $request->cookies->count() > 0;
	}

	/**
	 * Applies a transition only if it is allowed from the current state.
	 */
	protected function applyOrderTransition(OrderInterface $order, string $transition_id): bool {
	  $state = $order->getState();
	  $allowed = $state->getTransitions();
	  if (!isset($allowed[$transition_id])) {
		return FALSE;
	  }
	  $state->applyTransition($allowed[$transition_id]);
	  $order->save();
	  return TRUE;
	}

	/**
	 * Saves the payment and logs the IPN result.
	 */
	protected function saveTransaction($transaction, OrderInterface $order, array &$response_data): void {
	  $this->entityTypeManager->getStorage('commerce_payment')->save($transaction);
	  $response_data['transaction_id'] = $transaction->id();
	  $this->logger->info('IPN processed OK for Order @order_number with ID @txn_id.', [
		'@txn_id' => $response_data['transaction_id'],
		'@order_number' => $order->id(),
	  ]);
	}

    /**
     * Standalone, styled message page: red cross icon, title, message, "Return to home" button.
     */
    private function notifyPage($title, $message, $statusCode = 200, $success = false, $type = null)
    {
        // Customer's browser: use Drupal's message area and redirect.
        /* if ($this->isBrowserRequest()) {
			$messenger = \Drupal::messenger();
			if ($success) {
				$messenger->addStatus($message);
				$route = '<front>';
			}
			else {
				if ($type === 'warning') {
					$messenger->addWarning($message);
				}
				else {
					$messenger->addError($message);
				}
				$route = 'commerce_cart.page';
			}
			return new RedirectResponse(Url::fromRoute('commerce_cart.page')->toString());
		} */

        $e = function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
		$front_url = \Drupal\Core\Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString();

        $html = '<!DOCTYPE html>
		<html lang="en">
		<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta name="robots" content="noindex, nofollow">
		<title>' . $e($title) . '</title>
		<style>
		  :root { --bg:#f4f6f9; --card:#ffffff; --text:#1f2933; --muted:#5f6b7a; --red:#d93025; --red-bg:#fdecea; --green:#1e8e3e; --btn:#1f2933; --btn-text:#ffffff; --shadow:0 10px 30px rgba(15,23,42,.10); }
		  @media (prefers-color-scheme: dark) {
			:root { --bg:#12161c; --card:#1b212a; --text:#eef1f5; --muted:#a3adba; --red:#ff6b61; --red-bg:#3a1f1d; --green:#5bd37a; --btn:#eef1f5; --btn-text:#12161c; --shadow:0 10px 30px rgba(0,0,0,.5); }
		  }
		  * { box-sizing:border-box; }
		  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:16px; background:var(--bg); color:var(--text); font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; line-height:1.5; }
		  .card { width:100%; max-width:460px; background:var(--card); border-radius:16px; box-shadow:var(--shadow); padding:40px 32px 32px; text-align:center; }
		  .icon { width:84px; height:84px; margin:0 auto 20px; border-radius:50%; background:var(--red-bg); display:flex; align-items:center; justify-content:center; }
		  .icon svg { width:44px; height:44px; }
		  h1 { margin:0 0 10px; font-size:24px; font-weight:700; }
		  p { margin:0 0 28px; font-size:16px; color:var(--muted); overflow-wrap:anywhere; }
		  .btn { display:inline-block; padding:13px 28px; border-radius:10px; background:var(--btn); color:var(--btn-text); font-size:16px; font-weight:600; text-decoration:none; }
		  .btn:hover, .btn:focus { opacity:.88; }
		  .store { margin-top:24px; font-size:13px; color:var(--muted); }
		</style>
		</head>
		<body>
		  <main class="card" role="alert">
			<div class="icon" aria-hidden="true">';
			if ($success) {
				$html .= '<svg viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">'
                . '<polyline class="tick" points="5 12.5 10 17.5 19 7.5"></polyline></svg>';
			} else {
			 $html .= ' <svg viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
				<line x1="5" y1="5" x2="19" y2="19"></line>
				<line x1="19" y1="5" x2="5" y2="19"></line>
			  </svg>';
			}
			$html .= '</div>
			<h1>' . $e($title) . '</h1>
			<p>' . $e($message) . '</p>
			<a class="btn" href="' . $front_url . '">Return to home</a>
		  </main>
		</body>
		</html>';

				return $html;
	}

}