<?php

namespace Drupal\commerce_cointopay\Controller;

use Drupal\commerce\Response\NeedsRedirectException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\commerce_cointopay\IPNCPHandlerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Returns responses for commerce_cointopay module routes.
 */
class CoinToPayController extends ControllerBase {

    /**
     * The IPNCP handler.
     *
     * @var \Drupal\commerce_cointopay\IPNCPHandlerInterface
     */
    protected $ipnCPHandler;

    /**
     * Constructs a \Drupal\commerce_cointopay\Controller\CoinToPayController object.
     *
     * @param \Drupal\commerce_cointopay\IPNCPHandlerInterface $ipn_cp_handler
     *   The IPN CPhandler.
     */
    public function __construct(IPNCPHandlerInterface $ipn_cp_handler) {
        $this->ipnCPHandler = $ipn_cp_handler;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container) {
        return new static(
            $container->get('commerce_cointopay.ipn_cp_handler')
        );
    }

    /**
     * Process the IPN by calling IPNCPHandler service object.
     *
     * @return object
     *   A json object.
     */
    public function processIPN(Request $request) {

        // Get IPN request data and basic processing for the IPN request.
        $status = $this->ipnCPHandler->process($request);
        $response = new Response();
        $response->setContent(json_encode(['Status' => $status]));
        $response->headers->set('Content-Type', 'application/json');
        if($status) {
            throw new NeedsRedirectException(Url::fromRoute('commerce_checkout.form', [
                'commerce_order' => $request->get('CustomerReferenceNr'),
                'step' => 'complete',
            ])->toString());
        }else{
            throw new NeedsRedirectException(Url::fromRoute('commerce_payment.checkout.form', [
                    'commerce_order' => $request->get('CustomerReferenceNr'),
                    'step' => 'fail']
            )->toString());
        }
    }

}
