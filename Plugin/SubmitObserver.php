<?php

namespace Magento\SplitOrder\Plugin;

class SubmitObserver
{
    public function beforeExecute($subject, $observer)
    {
        $order = $observer->getEvent()->getOrder();
        $quote = $observer->getEvent()->getQuote();
        if ($quote->getChildOrderQuote()) {
            $order->setCanSendNewEmailFlag(false);
        }
        return [$observer];
    }
}
