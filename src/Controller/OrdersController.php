<?php

namespace App\Controller;

use App\Entity\Coffee;
use App\Entity\Order;
use App\Entity\OrderedCoffee;
use App\Entity\User;
use App\Model\Coffee\OrderDto;
use App\Service\OrderService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\Controller\Annotations\Delete;
use FOS\RestBundle\Controller\Annotations\Get;
use FOS\RestBundle\Controller\Annotations\Post;
use FOS\RestBundle\Controller\Annotations\View;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;


class OrdersController extends AbstractController
{
    /** @var EntityManagerInterface */
    private $em;

    /** @var OrderService */
    private $orderService;

    public function __construct(EntityManagerInterface $em,
                                OrderService $orderService)
    {
        $this->em = $em;
        $this->orderService = $orderService;
    }

    /**
     * Crée une nouvelle commande.
     *
     * @View()
     * @Post("/api/order")
     *
     * @ParamConverter("data", converter="fos_rest.request_body")
     *
     * @param Request $request
     * @param UserInterface $user
     * @param OrderDto $data
     * @return Order
     */
    public function orderAction(Request $request, UserInterface $user, OrderDto $data)
    {
        $userRepo = $this->em->getRepository(User::class);
        $coffeeRepo = $this->em->getRepository(Coffee::class);
        $order = new Order();
        $order->setUser($userRepo->findOneByUsername($user->getUsername()));
        $order->setItems(new ArrayCollection());

        foreach ($data->getItems() as $item) {
            $orderedCoffee = new OrderedCoffee();
            $orderedCoffee->setOrder($order);
            $orderedCoffee->setCoffee($coffeeRepo->find($item->getId()));
            $orderedCoffee->setQuantity30($item->getQuantity30());
            $orderedCoffee->setQuantity50($item->getQuantity50());
            $order->getItems()->add($orderedCoffee);
        }

        $this->em->merge($order);
        $this->em->flush();
        return $order;
    }

    /**
     * Récupère toutes les commandes en attente de l'utilisateur courant.
     *
     * @View()
     * @Get("/api/orders/waiting")
     *
     * @param Request $request
     * @param UserInterface $user
     * @return OrderDto[]
     */
    public function waitingOrdersAction(Request $request, UserInterface $user)
    {
        $orderRepo = $this->em->getRepository(Order::class);
        /** @var Order[] $orders */
        $orders = $orderRepo->findWaitingOrdersForUser($user);

        /** @var OrderDto[] $ordersDto */
        $ordersDto = array_map(function (Order $order) {
            return $this->orderService->convertToDto($order);
        }, $orders);
        return $ordersDto;
    }

    /**
     * Récupère toutes les commandes en attente.
     *
     * @View()
     * @Get("/api/orders/waiting-all")
     * @Security("has_role('ROLE_ADMIN')")
     *
     * @param Request $request
     * @return OrderDto[]
     */
    public function allWaitingOrdersAction(Request $request)
    {
        $orderRepo = $this->em->getRepository(Order::class);
        /** @var Order[] $orders */
        $orders = $orderRepo->findWaitingOrders();

        /** @var OrderDto[] $ordersDto */
        $ordersDto = array_map(function (Order $order) {
            return $this->orderService->convertToDto($order, true);
        }, $orders);
        return $ordersDto;
    }

    /**
     * Supprime une commande.
     *
     * @View()
     * @Delete("/api/orders/{id}", requirements={"id"="\d+"})
     *
     * @param Request $request
     * @param UserInterface $user
     * @param string $id
     * @return OrderDto
     */
    public function deleteOrderAction(Request $request, UserInterface $user, string $id)
    {
        $orderRepo = $this->em->getRepository(Order::class);
        /** @var Order $order */
        $order = $orderRepo->find($id);

        if ($order->getUser()->getUsername() !== $user->getUsername() && !in_array('ROLE_ADMIN', $user->getRoles())) {
            throw new \Exception('Vous n\'avez pas le droit de supprimer cette commande.');
        }

        $this->em->remove($order);
        $this->em->flush();

        /** @var OrderDto $orderDto */
        $orderDto = $this->orderService->convertToDto($order);
        return $orderDto;
    }

    /**
     * Valide toutes les commandes en attente.
     *
     * @View()
     * @Post("/api/orders/waiting-all/validate")
     * @Security("has_role('ROLE_ADMIN')")
     *
     * @param Request $request
     * @return OrderDto[]
     */
    public function validateAllWaitingOrdersAction(Request $request)
    {
        $orderRepo = $this->em->getRepository(Order::class);
        /** @var Order[] $orders */
        $orders = $orderRepo->findWaitingOrders();

        $now = new \DateTime();
        foreach ($orders as $order)
        {
            $order->setIsWaiting(false);
            $order->setValidationDate($now);
            $this->em->persist($order);
        }

        $this->em->flush();

        /** @var OrderDto[] $ordersDto */
        $ordersDto = array_map(function (Order $order) {
            return $this->orderService->convertToDto($order, true);
        }, $orders);
        return $ordersDto;
    }
}