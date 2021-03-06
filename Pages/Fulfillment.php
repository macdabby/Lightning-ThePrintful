<?php

namespace lightningsdk\checkout_printful\Pages;

use Exception;
use lightningsdk\core\Tools\Messenger;
use lightningsdk\core\Tools\Navigation;
use lightningsdk\core\View\Page;
use lightningsdk\core\Tools\ClientUser;
use lightningsdk\core\Tools\Communicator\RestClient;
use lightningsdk\core\Tools\Configuration;
use lightningsdk\core\Tools\Request;
use lightningsdk\core\Tools\Template;
use lightningsdk\checkout\Model\LineItem;
use lightningsdk\checkout\Model\Order;

class Fulfillment extends Page {

    protected $rightColumn = false;
    protected $page = ['fulfillment', 'lightningsdk/printful'];
    protected $share = false;

    public function hasAccess() {
        return ClientUser::requireAdmin();
    }

    /**
     * Loads the order for confirmation.
     */
    public function get() {
        $order = Order::loadByID(Request::get('id', Request::TYPE_INT));
        Template::getInstance()->set('order', $order);
    }

    /**
     * Submits the fulfillment request.
     *
     * @throws Exception
     */
    public function post() {
        $order = Order::loadByID(Request::post('id', Request::TYPE_INT));
        if (empty($order)) {
            throw new Exception('Could not load order.');
        }

        // Prepare the shipping address.
        $address = $order->getShippingAddress();
        if (empty($address)) {
            throw new Exception('Could not load address.');
        }
        $recipient = [
            'name' => $address->name,
            'address1' => $address->street,
            'address2' => $address->street2,
            'city' => $address->city,
            'state_code' => $address->state,
            'country_code' => $address->country,
            'zip' => $address->zip,
        ];

        // Figure out white items to ship.
        $items = $itemObjects = [];
        foreach ($order->getItemsToFulfillWithHandler('printful') as $item) {
            /* @var LineItem $item */

            if ($warehouseItem = $item->getAggregateOption('printful_warehouse_variant')) {
                $items[] = [
                    'warehouse_product_variant_id' => intval($warehouseItem),
                    'quantity' => $item->qty,
                    'retail_price' => $item->amount,
                ];
            } elseif ($images = $item->getAggregateOption('printful_image')) {
                // Prepare the images.
                $image_array = [];
                if (!is_array($images)) {
                    $images = [$images];
                }
                foreach ($images as $i) {
                    // TODO: This should be able to handle images that are saved with metadata.
                    $image_array[] = ['id' => $i];
                }

                // Prepare the rest of the item.
                $items[] = [
                    // Must be unique for each item shipped.
                    'external_id' => $item->id,
                    // Description of printful product, including size and color.
                    'variant_id' => $item->getAggregateOption('printful_product'),
                    'quantity' => $item->qty,
                    'files' => $image_array,
                    'retail_price' => $item->amount,
                ];
            } else {
                throw new Exception('Printful item not properly configured.');
            }

            // Save the object for later.
            $itemObjects[] = $item;
        }

        if (empty($items)) {
            throw new Exception('No items to ship.');
        }

        // Send to printful.
        $client = new RestClient('https://api.printful.com/');
        $client->sendJSON(true);

        $client->setBasicAuth(
            Configuration::get('modules.printful.api_user'),
            Configuration::get('modules.printful.api_password'));
        $client->set('external_id', $order->order_id);
        $client->set('recipient', $recipient);
        $client->set('items', $items);
        if ($client->callPost('/orders')) {
            foreach ($itemObjects as $item) {
                $item->markFulfilled();
            }
            // This only marks it fulfilled if all items have been.
            $order->markFulfilled();
            Messenger::message('The order has been processed.');
            Navigation::redirect('/admin/orders');
        } else {
            throw new Exception('There was a problem submitting the order: ' . $client->get('error.message'));
        }
    }
}
