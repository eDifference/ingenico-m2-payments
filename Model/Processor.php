<?php

namespace Ingenico\Payment\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Helper\Data as SalesData;
use Magento\Sales\Model\Order\Invoice;
use Magento\Framework\Registry;
use Ingenico\Payment\Model\Config as IngenicoConfig;
use Ingenico\Payment\Model\Connector as IngenicoConnector;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\CreditmemoSender;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface as TransactionBuilder;
use Magento\Sales\Api\CreditmemoRepositoryInterface;

class Processor
{
    /**
     * @var OrderFactory
     */
    private $orderFactory;

    /**
     * @var InvoiceRepositoryInterface
     */
    private $invoiceRepository;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var CreditmemoManagementInterface
     */
    private $creditmemoManagement;

    /**
     * @var CreditmemoFactory
     */
    private $creditmemoFactory;

    /**
     * @var InvoiceService
     */
    private $invoiceService;

    /**
     * @var InvoiceSender
     */
    private $invoiceSender;

    /**
     * @var CreditmemoSender
     */
    private $creditmemoSender;

    /**
     * @var TransactionBuilder
     */
    private $transactionBuilder;

    /**
     * @var CreditmemoRepositoryInterface
     */
    private $creditmemoRepository;

    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var TransactionFactory
     */
    private $transactionFactory;

    /**
     * @var SalesData
     */
    private $salesData;

    /**
     * @var IngenicoConfig
     */
    private $config;

    /**
     * @var IngenicoConnector
     */
    private $connector;

    /**
     * Constructor
     */
    public function __construct(
        OrderFactory $orderFactory,
        InvoiceRepositoryInterface $invoiceRepository,
        OrderRepositoryInterface $orderRepository,
        CreditmemoManagementInterface $creditmemoManagement,
        CreditmemoFactory $creditmemoFactory,
        InvoiceService $invoiceService,
        InvoiceSender $invoiceSender,
        CreditmemoSender $creditmemoSender,
        TransactionBuilder $transactionBuilder,
        CreditmemoRepositoryInterface $creditmemoRepository,
        TransactionFactory $transactionFactory,
        SalesData $salesData,
        Registry $registry,
        IngenicoConfig $config
    ) {
        $this->orderFactory      = $orderFactory;
        $this->invoiceRepository = $invoiceRepository;
        $this->orderRepository  = $orderRepository;
        $this->creditmemoManagement = $creditmemoManagement;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->creditmemoRepository = $creditmemoRepository;
        $this->invoiceService = $invoiceService;
        $this->invoiceSender = $invoiceSender;
        $this->creditmemoSender = $creditmemoSender;
        $this->transactionBuilder = $transactionBuilder;
        $this->transactionFactory = $transactionFactory;
        $this->salesData = $salesData;
        $this->registry = $registry;
        $this->config = $config;
    }

    /**
     * Set Connector.
     *
     * @param IngenicoConnector $connector
     *
     * @return $this
     */
    public function setConnector(IngenicoConnector $connector)
    {
        $this->connector = $connector;

        return $this;
    }

    /**
     * Load order object using increment_id
     *
     * @param $incrementId
     *
     * @return \Magento\Sales\Model\Order
     * @throws LocalizedException
     */
    public function getOrderByIncrementId($incrementId)
    {
        if (!$incrementId) {
            throw new LocalizedException(__('ingenico.exception.message8'));
        }

        $order = $this->orderFactory->create()->loadByIncrementId($incrementId);
        if (!$order->getId()) {
            throw new LocalizedException(__('ingenico.exception.message9', $incrementId));
        }

        return $order;
    }

    /**
     * Process successful order payment (authorize).
     *
     * @param string $incrementId
     * @param \IngenicoClient\Payment $paymentResult
     * @param string $message
     *
     * @return \Magento\Sales\Api\Data\OrderInterface
     * @throws LocalizedException
     */
    public function processOrderAuthorization($incrementId, $paymentResult, $message)
    {
        $order = $this->getOrderByIncrementId($incrementId);
        $authorizedStatus = $this->config->getOrderStatusAuth($order);

        // skip already authorized orders (double ping-back)
        if ($order->getStatus() == $authorizedStatus) {
            return $order;
        }

        // Set order status
        $status = $this->config->getAssignedState($authorizedStatus);

        $order->setData('state', $status->getState());
        $order->setStatus($status->getStatus());

        $this->_addOrderMessage($order, $message, __('ingenico.notification.message3'));
        $this->registry->register($this->connector::REGISTRY_KEY_CAN_SEND_AUTH_EMAIL, true, true);

        return $this->orderRepository->save($order);
    }

    /**
     * Process successful order payment (capture).
     *
     * @param string $incrementId
     * @param \IngenicoClient\Payment $paymentResult
     * @param string $message
     *
     * @return \Magento\Sales\Api\Data\OrderInterface|\Magento\Sales\Model\Order
     * @throws LocalizedException
     */
    public function processOrderPayment($incrementId, $paymentResult, $message)
    {
        $order = $this->getOrderByIncrementId($incrementId);

        // only process if request is not from Admin Panel
        if ($this->registry->registry('current_invoice')) {
            return $order;
        }

        $new_status = $this->config->getOrderStatusSale($order);
        $trxId = $paymentResult->getPayId() . '-' . $paymentResult->getPayIdSub();
        if ($order->hasInvoices()) {
            // Check if there is an Invoice with transaction ID
            foreach ($order->getInvoiceCollection() as $invoice) {
                /** @var Invoice $invoice */
                if ($invoice->getTransactionId() === $trxId) {
                    $invoice->pay();
                    $order->addRelatedObject($invoice);

                    // Set order status
                    $status = $this->config->getAssignedState($new_status);
                    $order->setData('state', $status->getState());
                    $order->setStatus($status->getStatus());
                    $this->_addOrderMessage($order, $message, __('ingenico.notification.message6'));

                    $this->orderRepository->save($order);

                    if (!$invoice->getEmailSent() && $this->salesData->canSendNewInvoiceEmail()) {
                        $this->invoiceSender->send($invoice);
                    }

                    return $order;
                }
            }
        }

        // Create Invoice
        try {
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase($invoice::CAPTURE_ONLINE);
            $invoice->register();
            $invoice->getOrder()->setIsInProcess(true);
            $invoice->setIsPaid(true);

            if ($paymentResult->getPm() !== 'Bank transfer') {
                $invoice->setTransactionId($trxId);
            }

            // Set order status
            $status = $this->config->getAssignedState($new_status);
            $order->setData('state', $status->getState());
            $order->setStatus($status->getStatus());
            $this->_addOrderMessage($order, $message, __('ingenico.notification.message6'));

            $dbTransaction = $this->transactionFactory->create();
            $dbTransaction->addObject($invoice)
                          ->addObject($order)
                          ->save();

            if ($this->salesData->canSendNewInvoiceEmail()) {
                $this->invoiceSender->send($invoice);
            }

            return $order;
        } catch (LocalizedException $e) {
            $this->connector->log(sprintf('%s %s', __METHOD__, $e->getMessage()));

            throw $e;
        } catch (\Exception $e) {
            $this->connector->log($e->getMessage(), 'critical');
        }

        return $order;
    }

    /**
     * Deprecated from v2.2.1, use processOrderDefault()
     */
    public function processOrderCaptureProcessing($incrementId, $paymentResult, $message)
    {
        return $this->processOrderDefault($incrementId, $paymentResult, $message);
    }

    /**
     * Deprecated from v2.2.1, use processOrderDefault()
     */
    public function processOrderRefundProcessing($incrementId, $paymentResult, $message)
    {
        return $this->processOrderDefault($incrementId, $paymentResult, $message);
    }

    /**
     * Simply add record to order history, nothing else
     */
    public function processOrderDefault($incrementId, $paymentResult, $message)
    {
        $order = $this->getOrderByIncrementId($incrementId);
        $this->_addOrderMessage($order, $message);

        return $this->orderRepository->save($order);
    }

    /**
     * Process successful order refund.
     *
     * @param string $incrementId
     * @param \IngenicoClient\Payment $paymentResult
     * @param string $message
     *
     * @return \Magento\Sales\Api\Data\OrderInterface
     * @throws LocalizedException
     */
    public function processOrderRefund($incrementId, $paymentResult, $message)
    {
        $order = $this->getOrderByIncrementId($incrementId);

        if ($order->isCanceled()) {
            $this->_addOrderMessage($order, __('ingenico.notification.message5'));

            return $this->orderRepository->save($order);
        }

        try {
            // check if there is a Credit Memo with transaction ID
            $trxId = $paymentResult->getPayId() . '-' . $paymentResult->getPayIdSub();
            $hasFound = false;
            foreach ($order->getCreditmemosCollection() as $creditMemo) {
                /** @var \Magento\Sales\Model\Order\Creditmemo $creditMemo */
                if ($creditMemo->getTransactionId() === $trxId && $creditMemo->canRefund()) {
                    $creditMemo->setPaymentRefundDisallowed(true);
                    // @see \Magento\Sales\Model\Service\CreditmemoService::refund()
                    $this->creditmemoManagement->refund($creditMemo, false);
                    $this->creditmemoSender->send($creditMemo);

                    $hasFound = true;
                }
            }

            if (!$hasFound) {
                // Try to find the invoice
                $invoiceToRefund = null;
                if ($order->hasInvoices()) {
                    foreach ($order->getInvoiceCollection() as $invoice) {
                        /** @var \Magento\Sales\Model\Order\Invoice $invoice */
                        if ($invoice->canRefund()) {
                            $invoiceToRefund = $invoice;
                            break;
                        }
                    }
                }

                /** @var \Magento\Sales\Model\Order\Creditmemo $creditMemo */
                $creditMemo = $this->creditmemoFactory->createByOrder($order);
                $creditMemo->setTransactionId($trxId)
                    ->setInvoice($invoiceToRefund)
                    ->setBaseGrandTotal($paymentResult->getAmount())
                    ->setGrandTotal($paymentResult->getAmount())
                    ->setPaymentRefundDisallowed(true);

                $this->creditmemoManagement->refund($creditMemo);
                $this->creditmemoSender->send($creditMemo);
            }

            $order = $this->orderRepository->get($order->getId());
            $this->_addOrderMessage($order, $message);
        } catch (LocalizedException $e) {
            $this->connector->log(sprintf('%s::%s %s', __CLASS__, __METHOD__, $e->getMessage()));
        } catch (\Exception $e) {
            $this->connector->log($e->getMessage(), 'critical');
        }

        return $this->orderRepository->save($order);
    }

    /**
     * Process order cancellation.
     *
     * @param string $incrementId
     * @param \IngenicoClient\Payment $paymentResult
     * @param string $message
     *
     * @return \Magento\Sales\Api\Data\OrderInterface
     * @throws LocalizedException
     */
    public function processOrderCancellation($incrementId, $paymentResult, $message = null)
    {
        $order = $this->getOrderByIncrementId($incrementId);

        if (!$order->canCancel()) {
            $this->_addOrderMessage($order, __('ingenico.notification.message7', $message));

            return $this->orderRepository->save($order);
        }

        $order->cancel();
        $this->_addOrderMessage($order, $message, __('ingenico.notification.message8'));

        return $this->orderRepository->save($order);
    }

    protected function _addOrderMessage($order, $message, $fallbackMsg = null)
    {
        $order->addStatusToHistory(
            $order->getStatus(),
            $message ? $message : $fallbackMsg
        );
    }
}
