<?php

namespace Drupal\commerce_cointopay\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_log\Entity\Log;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "cointopay_redirect",
 *   label = "Cointopay - Pay with Bitcoin, Litecoin, and other cryptocurrencies (Off-site redirect)",
 *   display_label = "Cointopay",
 *    forms = {
 *     "offsite-payment" = "Drupal\commerce_cointopay\PluginForm\CoinToPayRedirect\CoinToPayForm",
 *   },
 *   payment_method_types = {"credit_card"},
 * )
 */
class CoinToPayRedirect extends OffsitePaymentGatewayBase
{

    /**
     * {@inheritdoc}
     */
    public function buildConfigurationForm(array $form, FormStateInterface $form_state)
    {
        $form = parent::buildConfigurationForm($form, $form_state);

        $merchant_id = !empty($this->configuration['merchant_id']) ? $this->configuration['merchant_id'] : '';
        $security_code = !empty($this->configuration['security_code']) ? $this->configuration['security_code'] : '';
        $ipn_logging = !empty($this->configuration['ipn_logging']) ? $this->configuration['ipn_logging'] : '';
        $coin_id = !empty($this->configuration['coin_id']) ? $this->configuration['coin_id'] : 1;

        $form['merchant_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Cointopay Merchant ID'),
            '#default_value' => $merchant_id,
            '#description' => $this->t('The Merchant ID of your Cointopay account.'),
            '#required' => TRUE,
            '#ajax' => [
                'callback' => [$this, 'ajaxCallback'],
                'wrapper' => 'ajax-wrapper',
                'event' => 'change',
                'method' => 'replace',
            ],
        ];

        $form['security_code'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Security Code'),
            '#default_value' => $security_code,
            '#description' => $this->t('Set on the Edit Settings page at Cointopay.com'),
            '#required' => TRUE,
        ];

        $options = $this->getCoins($merchant_id);

        $form['coin_id'] = [
            '#type' => 'select',
            '#prefix' => '<div id="ajax_wrapper">',
            '#suffix' => '</div>',
            '#title' => $this->t('Default currency'),
            '#options' => $options,
            '#validated' => TRUE,
            '#default_value' => $coin_id,
            '#description' => $this->t('Transactions in other currencies will be converted to this currency, so multi-currency sites must be configured to use appropriate conversion rates.'),
            '#required' => TRUE,
        ];

        $form['ipn_logging'] = [
            '#type' => 'radios',
            '#title' => $this->t('IPN logging'),
            '#options' => [
                'no' => $this->t('Only log IPN errors.'),
                'yes' => $this->t('Log full IPN data (used for debugging).'),
            ],
            '#default_value' => $ipn_logging,
        ];

        $form['mode']['#access'] = FALSE;

        return $form;
    }

    /**
     * @param array $form
     * @param FormStateInterface $form_state
     * @return AjaxResponse
     */
    public function ajaxCallback(array &$form, FormStateInterface $form_state) {
        $ajax_response = new AjaxResponse();
        $merchant_id = $form_state->getTriggeringElement()['#value'];
        $merchant_id = empty($merchant_id) ? 12 : $merchant_id;
        $options = $this->getCoins($merchant_id);

        $ajax_response->addCommand(new HtmlCommand('#ajax_wrapper',
            $form['coin_id'] = [
                '#type' => 'select',
                '#title' => $this->t('Default currency'),
                '#options' => $options,
                '#default_value' => 1,
                '#description' => $this->t('Chose currency.')
            ]
        ));
        return $ajax_response;
    }

    /**
     * @param string $merchant_id
     * @return array
     */
    public function getCoins($merchant_id = '') {
        if(empty($merchant_id)){
            return [];
        }
        else{
            $url_params = [
                'MerchantID' => $merchant_id,
                'output' => 'json',
                'JsonArray' => 1
            ];
            $redirect_url = 'https://cointopay.com/CloneMasterTransaction';
            $get_request = new Client(['verify' => false]);
            try {
                $response = $get_request->request('GET', $redirect_url, [
                    'query' => $url_params
                ]);

                if ($response->getStatusCode() != 200) {
                    $options = [];
                } else {
                    $response = $response->getBody()->getContents();
                    $options = json_decode($response, true);
                    $coins = [];
                    foreach ($options as $option) {
                        if(!empty($option['id'])) {
                            $coins[$option['id']] = $option['name'];
                        }
                    }
                }
                return $coins;
            }catch (RequestException $e){
                return [];
            }
            }
    }
    /**
     * {@inheritdoc}
     */
    public function defaultConfiguration()
    {
        return [
                'ipn_logging' => 'yes',
                'merchant_id' => '',
                'security_code' => '',
                'coin_id' => ''
            ] + parent::defaultConfiguration();
    }

    /**
     * {@inheritdoc}
     */
    public function validateConfigurationForm(array &$form, FormStateInterface $form_state)
    {
        parent::validateConfigurationForm($form, $form_state);

        if (!$form_state->getErrors() && $form_state->isSubmitted()) {
            $values = $form_state->getValue($form['#parents']);
            $this->configuration['merchant_id'] = $values['merchant_id'];
            $this->configuration['security_code'] = $values['security_code'];
            $this->configuration['ipn_logging'] = $values['ipn_logging'];
            $this->configuration['coin_id'] = $values['coin_id'];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
    {
        parent::submitConfigurationForm($form, $form_state);
        if (!$form_state->getErrors()) {
            $values = $form_state->getValue($form['#parents']);
            $this->configuration['merchant_id'] = $values['merchant_id'];
            $this->configuration['security_code'] = $values['security_code'];
            $this->configuration['ipn_logging'] = $values['ipn_logging'];
            $this->configuration['coin_id'] = $values['coin_id'];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onCancel(OrderInterface $order, Request $request)
    {
        $status = $request->get('status');
        drupal_set_message($this->t('Payment @status on @gateway but may resume the checkout process here when you are ready.', [
            '@status' => $status,
            '@gateway' => $this->getDisplayLabel(),
        ]), 'error');
    }

}
