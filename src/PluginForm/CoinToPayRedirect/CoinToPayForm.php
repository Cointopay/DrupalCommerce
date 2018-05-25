<?php

namespace Drupal\commerce_cointopay\PluginForm\CoinToPayRedirect;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class CoinToPayForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $payment_configuration = $payment_gateway_plugin->getConfiguration();
    /** @var \Drupal\commerce_order\Entity\Order $order */
    $order = $payment->getOrder();
//echo Url::fromRoute('commerce_payment.checkout.cancel', ['commerce_order' => $order->id(), 'step' => 'cancel'], ['absolute' => TRUE])->toString();exit;
    $url_params = [
        'Checkout' => 'true',
        'MerchantID' => $payment_configuration['merchant_id'],
        'Amount' => $order->getTotalPrice()->getNumber(),
        'AltCoinID' => (integer)$payment_configuration['coin_id'],
        'CustomerReferenceNr' => $order->id(),
        'SecurityCode' => $payment_configuration['security_code'],
        'output' => 'json',
        'inputCurrency' => $order->getTotalPrice()->getCurrencyCode(),
        'transactionconfirmurl' => Url::fromRoute('commerce_cointopay.processipn', [], ['absolute' => TRUE])->toString(),
        'transactionfailurl' => Url::fromRoute('commerce_payment.checkout.cancel', ['commerce_order' => $order->id(), 'step' => 'cancel'], ['absolute' => TRUE])->toString(),

    ];
    foreach ($url_params as $name => $value) {
      if (isset($value)) {
          $form[$name] = ['#type' => 'hidden', '#value' => $value];
      }
    }
    $redirect_url = 'https://cointopay.com/MerchantAPI';
    $get_request = new Client(['verify' => false]);
    try{
        $response = $get_request->request('GET', $redirect_url, [
            'query' => $url_params
        ]);
    }catch (RequestException $e) {
        throw new BadRequestHttpException($e->getMessage());
    }

    if ($response->getStatusCode() != 200) {
        throw new BadRequestHttpException('Coin to pay is not responding, please try later');
    }
    $response = $response->getBody()->getContents();
    $response = json_decode($response);

    $short_url = $response->shortURL;
    return $this->buildRedirectForm($form, $form_state, $short_url, [], 'get');
  }

  /**
   * Returns a unique invoice number based on the Order ID and timestamp.
   *
   * @param array $ipn_data
   *   Order object
   *
   * @return string
   *   Invoice generated from order object.
   */
  public function commerce_cointopay_ipn_invoice($order) {
    /** @var \Drupal\commerce_order\Entity\Order $order */
    return $order->id() . '-' . \Drupal::time()->getRequestTime();
  }

  /**
   * Returns the IPN URL.
   *
   * @param $method_id
   *   Optionally specify a payment method instance ID to include in the URL.
   */
  public function commerce_cointopay_ipn_url($instance_id = NULL) {
    $parts = [
      'commerce_cointopay',
      'ipn',
    ];

    if (!empty($instance_id)) {
      $parts[] = $instance_id;
    }

    return Url::fromUri(implode('/', $parts), ['absolute' => TRUE]);
  }

}
