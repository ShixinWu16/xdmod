<?php

namespace Rest\Controllers;

use CCR\DB;
use CCR\Log;
use DataWarehouse\Export\FileManager;
use DataWarehouse\Export\QueryHandler;
use DataWarehouse\Export\RealmManager;
use DateTime;
use Exception;
use Silex\Application;
use Silex\ControllerCollection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WarehouseExportControllerProvider extends BaseControllerProvider
{
    // Constants used in log messages.
     const LOG_MODULE_KEY = 'module';
     const LOG_MODULE = 'data-warehouse-export';
     const LOG_MESSAGE_KEY = 'message';
     const LOG_EVENT_KEY = 'event';
     const LOG_ID_KEY = 'id';
     const LOG_USER_ID_KEY = 'user_id';

     // Constants used in JSON responses.
     const JSON_SUCCESS_KEY = 'success';
     const JSON_MESSAGE_KEY = 'message';
     const JSON_DATA_KEY = 'data';
     const JSON_TOTAL_KEY = 'total';

    /**
     * @var DataWarehouse\Export\QueryHandler
     */
    private $queryHandler;

    /**
     * @var DataWarehouse\Export\RealmManager
     */
    private $realmManager;

    /**
     * @var \CCR\Log
     */
    private $logger;

    public function __construct(array $params = [])
    {
        parent::__construct($params);
        $this->logger = Log::factory(
            'data-warehouse-export-rest',
            [
                'console' => false,
                'file' => false,
                'mail' => false
            ]
        );
        $this->realmManager = new RealmManager();
        $this->queryHandler = new QueryHandler($this->logger);
    }

    /**
     * Set up data warehouse export routes.
     *
     * @param Application $app
     * @param ControllerCollection $controller
     */
    public function setupRoutes(
        Application $app,
        ControllerCollection $controller
    ) {
        $root = $this->prefix;
        $current = get_class($this);
        $conversions = '\Rest\Utilities\Conversions';

        $controller->get("$root/realms", "$current::getRealms");
        $controller->post("$root/request", "$current::createRequest");
        $controller->get("$root/requests", "$current::getRequests");
        $controller->delete("$root/requests", "$current::deleteRequests");

        $controller->get("$root/download/{id}", "$current::getExportedDataFile")
            ->assert('id', '\d+')
            ->convert('id', "$conversions::toInt");

        $controller->delete("$root/request/{id}", "$current::deleteRequest")
            ->assert('id', '\d+')
            ->convert('id', "$conversions::toInt");
    }

    /**
     * Get all the realms available for exporting for the current user.
     *
     * @param Request $request
     * @param Application $app
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     * @throws \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException
     */
    public function getRealms(Request $request, Application $app)
    {
        $user = $this->authorize($request);
        $realms = array_map(
            function ($realm) {
                return [
                    'id' => $realm->getName(),
                    'name' => $realm->getDisplay()
                ];
            },
            $this->realmManager->getRealmsForUser($user)
        );

        return $app->json(
            [
                self::JSON_SUCCESS_KEY => true,
                self::JSON_DATA_KEY => array_values($realms),
                self::JSON_TOTAL_KEY => count($realms)
            ]
        );
    }

    /**
     * Get all the existing export requests for the current user.
     *
     * @param Request $request
     * @param Application $app
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     * @throws \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException
     */
    public function getRequests(Request $request, Application $app)
    {
        $user = $this->authorize($request);
        $results = $this->queryHandler->listUserRequestsByState($user->getUserId());
        return $app->json(
            [
                self::JSON_SUCCESS_KEY => true,
                self::JSON_DATA_KEY => $results,
                self::JSON_TOTAL_KEY => count($results)
            ]
        );
    }

    /**
     * Create a new export request for the current user.
     *
     * @param Request $request
     * @param Application $app
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     * @throws \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException
     * @throws BadRequestHttpException
     */
    public function createRequest(Request $request, Application $app)
    {
        $user = $this->authorize($request);
        $realm = $this->getStringParam($request, 'realm', true);

        $realms = array_map(
            function ($realm) {
                return $realm->getName();
            },
            $this->realmManager->getRealmsForUser($user)
        );
        if (!in_array($realm, $realms)) {
            throw new BadRequestHttpException('Invalid realm');
        }

        $startDate = $this->getDateFromISO8601Param($request, 'start_date', true);
        $endDate = $this->getDateFromISO8601Param($request, 'end_date', true);
        $now = new DateTime();

        if ($startDate > $now) {
            throw new BadRequestHttpException('Start date cannot be in the future');
        }

        if ($endDate > $now) {
            throw new BadRequestHttpException('End date cannot be in the future');
        }

        $interval = $startDate->diff($endDate);

        if ($interval === false) {
            throw new BadRequestHttpException('Failed to calculate date interval');
        }

        if ($interval->invert === 1) {
            throw new BadRequestHttpException('Start date must be before end date');
        }

        $format = strtoupper($this->getStringParam($request, 'format', true));

        if (!in_array($format, ['CSV', 'JSON'])) {
            throw new BadRequestHttpException('format must be CSV or JSON');
        }

        try {
            $id = $this->queryHandler->createRequestRecord(
                $user->getUserId(),
                $realm,
                $startDate->format('Y-m-d'),
                $endDate->format('Y-m-d'),
                $format
            );
        } catch (Exception $e) {
            throw new BadRequestHttpException('Failed to create export request: ' . $e->getMessage());
        }

        return $app->json([
            self::JSON_SUCCESS_KEY => true,
            self::JSON_MESSAGE_KEY => 'Created export request',
            self::JSON_DATA_KEY => [['id' => $id]],
            self::JSON_TOTAL_KEY => 1
        ]);
    }

    /**
     * Get the requested data.
     *
     * @param Request $request
     * @param Application $app
     * @param int $id
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     * @throws \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException
     * @throws AccessDeniedHttpException
     * @throws NotFoundHttpException
     * @throws BadRequestHttpException
     */
    public function getExportedDataFile(Request $request, Application $app, $id)
    {
        $user = $this->authorize($request);

        $requests = array_filter(
            $this->queryHandler->listUserRequestsByState($user->getUserId()),
            function ($request) use ($id) {
                return $request['id'] == $id;
            }
        );

        if (count($requests) === 0) {
            throw new NotFoundHttpException('Export request not found');
        }

        // Using `array_shift` because `array_filter` preserves keys so the
        // request may not be at index 0.
        $request = array_shift($requests);

        if ($request['state'] !== 'Available') {
            throw new BadRequestHttpException('Requested data is not available');
        }

        $fileManager = new FileManager();
        $file = $fileManager->getExportDataFilePath($id);

        if (!is_file($file)) {
            throw new NotFoundHttpException('Exported data not found');
        }

        if (!is_readable($file)) {
            throw new AccessDeniedHttpException('Exported data is not readable');
        }

        $this->logger->info([
            self::LOG_MODULE_KEY => self::LOG_MODULE,
            self::LOG_MESSAGE_KEY => 'Sending data warehouse export file',
            self::LOG_EVENT_KEY => 'DOWNLOAD',
            self::LOG_ID_KEY => $id,
            self::LOG_USER_ID_KEY => $user->getUserId()
        ]);

        if ($request['downloaded_datetime'] === null) {
            $this->queryHandler->updateDownloadedDatetime($request['id']);
        }

        return $app->sendFile(
            $file,
            200,
            [
                'Content-type' => 'application/zip',
                'Content-Disposition' => sprintf(
                    'attachment; filename="%s"',
                    $fileManager->getZipFileName($request)
                )
            ]
        );
    }

    /**
     * Delete a single request.
     *
     * @param Request $request
     * @param Application $app
     * @param int $id
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     * @throws \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException
     * @throws NotFoundHttpException
     */
    public function deleteRequest(Request $request, Application $app, $id)
    {
        $user = $this->authorize($request);
        $count = $this->queryHandler->deleteRequest($id, $user->getUserId());

        if ($count === 0) {
            throw new NotFoundHttpException('Export request not found');
        }

        $this->logger->info([
            self::LOG_MODULE_KEY => self::LOG_MODULE,
            self::LOG_MESSAGE_KEY => 'Deleted data warehouse export request',
            self::LOG_EVENT_KEY => 'DELETE_BY_USER',
            self::LOG_ID_KEY => $id,
            self::LOG_USER_ID_KEY => $user->getUserId()
        ]);

        return $app->json([
            self::JSON_SUCCESS_KEY => true,
            self::JSON_MESSAGE_KEY => 'Deleted export request',
            self::JSON_DATA_KEY => [['id' => $id]],
            self::JSON_TOTAL_KEY => 1
        ]);
    }

    /**
     * Delete multiple requests.
     *
     * The request body content must be a JSON encoded array of request IDs.
     *
     * @param Request $request
     * @param Application $app
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     * @throws \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException
     * @throws NotFoundHttpException
     */
    public function deleteRequests(Request $request, Application $app)
    {
        $user = $this->authorize($request);

        $requestIds = [];

        try {
            $requestIds = @json_decode($request->getContent());

            if ($requestIds === null) {
                throw new Exception('Failed to decode JSON');
            }

            if (!is_array($requestIds)) {
                throw new Exception('Export request IDs must be in an array');
            }

            foreach ($requestIds as $id) {
                if (!is_int($id)) {
                    throw new Exception('Export request IDs must integers');
                }
            }
        } catch (Exception $e) {
            throw new BadRequestHttpException(
                'Malformed HTTP request content: ' . $e->getMessage()
            );
        }

        try {
            $dbh = DB::factory('database');
            $dbh->beginTransaction();

            foreach ($requestIds as $id) {
                $count = $this->queryHandler->deleteRequest($id, $user->getUserId());
                if ($count === 0) {
                    throw new NotFoundHttpException('Export request not found');
                }
                $this->logger->info([
                    self::LOG_MODULE_KEY => self::LOG_MODULE,
                    self::LOG_MESSAGE_KEY => 'Deleted data warehouse export request',
                    self::LOG_EVENT_KEY => 'DELETE_BY_USER',
                    self::LOG_ID_KEY => $id,
                    self::LOG_USER_ID_KEY => $user->getUserId()
                ]);
            }

            $dbh->commit();
        } catch (NotFoundHttpException $e) {
            $dbh->rollBack();
            throw $e;
        } catch (Exception $e) {
            $dbh->rollBack();
            throw new BadRequestHttpException('Failed to delete export requests');
        }

        return $app->json([
            self::JSON_SUCCESS_KEY => true,
            self::JSON_MESSAGE_KEY => 'Deleted export requests',
            self::JSON_DATA_KEY => array_map(
                function ($id) {
                    return ['id' => $id];
                },
                $requestIds
            ),
            self::JSON_TOTAL_KEY => count($requestIds)
        ]);
    }
}
