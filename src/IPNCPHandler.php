<?php

namespace Drupal\commerce_cointopay;

use Drupal\commerce_order\Entity\Order;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Schema\ArrayElement;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class IPNCPHandler implements IPNCPHandlerInterface
{

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
            'status' => $request->get('Status'),
            'not_enough' => $request->get('notenough'),
            'confirm_code' => $request->get('ConfirmCode')
        ];
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

            if (count($transaction) == 0) {
                $transaction = $payment_storage->create([
                    'state' => 'new',
                    'amount' => $order->getTotalPrice(),
                    'payment_gateway' => $order->get('payment_gateway')->getString(),
                    'order_id' => $order->id(),
                    'remote_id' => $response_data['transaction_id'],
                ]);
                $transaction->setState('new');
            }

            if ($response_data['status'] == 'paid') {
                $transaction->setRemoteState('place');
                $transaction->setState('place');
                $transition = $order->getState()->getWorkflow()->getTransition('place');
                $order->getState()->applyTransition($transition);
                $order->save();

                if (empty($request->get('notenough'))) {
                    $transaction->setRemoteState('completed');
                    $transaction->setState('completed');
                    $transition = $order->getState()->getWorkflow()->getTransition('fulfill');
                    $order->getState()->applyTransition($transition);
                    $order->save();
                    $commerece_log->generate($order, 'ctp_order_completed')->save();
                } else {
                    $transaction->setRemoteState('validation');
                    $transaction->setState('validation');
                    $transition = $order->getState()->getWorkflow()->getTransition('validate');
                    $order->getState()->applyTransition($transition);
                    $order->save();
                    $commerece_log->generate($order, 'not_enough')->save();
                }
            } else {
                $transaction->setRemoteState('canceled');
                $transaction->setState('canceled');
                $order_state_transition = $order->getState()->getWorkflow()->getTransition('cancel');
                $order->getState()->applyTransition($order_state_transition);
                $order->save();
                $commerece_log->generate($order, 'ctp_order_canceled')->save();
            }

            // Save the transaction information.
            $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
            $payment_storage->save($transaction);
            $response_data['transaction_id'] = $transaction->id();
            $this->logger->info('IPN processed OK for Order @order_number with ID @txn_id.', ['@txn_id' => $response_data['transaction_id'], '@order_number' => $order->id()]);
            return TRUE;
        } else {
            $this->logger->notice('Could not find order for TxnID: @txn_id, ignored.', ['@txn_id' => $response_data['transaction_id']]);
            return FALSE;
        }

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
        }else{
            if($data['status'] != $result['Status']) {
                $this->logger->alert('Deprecated data ! Your data do not match to Cointopay.');
                throw new BadRequestHttpException('Deprecated data ! Your data do not match to Cointopay.');
                $return =  false;
            }
        }
        return $return;
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

}