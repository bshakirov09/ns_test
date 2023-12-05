<?php

namespace NW\WebService\References\Operations\Notification;

class TsReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW = 1;
    public const TYPE_CHANGE = 2;

    /**
     * @throws \Exception
     */
    public function doOperation(): array
    {
        $data = (array)$this->getRequest('data');
        $resellerId = (int)$data['resellerId'];
        $notificationType = (int)$data['notificationType'];

        $result = [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail' => false,
            'notificationClientBySms' => [
                'isSent' => false,
                'message' => '',
            ],
        ];

        // Проверки на Validation resellerId и notificationType
        $this->validateResellerId($resellerId);
        $this->validateNotificationType($notificationType);

        // Получение информации о Reseller
        $reseller = Seller::getById($resellerId);
        $this->validateReseller($reseller);

        // Получение и валидация информации о клиенте
        $client = $this->getClient($data, $resellerId);
        $this->validateClient($client, $resellerId);

        // Получение информации о сотрудниках и differences
        $cr = Employee::getById((int) $data['creatorId']);
        $et = Employee::getById((int) $data['expertId']);
        $differences = $this->getDifferences($notificationType, $data);

        // Подготовка данных для шаблона notification
        $templateData = $this->buildTemplateData($data, $cr, $et, $differences, $resellerId);

        // Validation данных шаблона
        $this->validateTemplateData($templateData);

        // Получение email reseller для notification
        $emailFrom = getResellerEmailFrom($resellerId);

        // Отправка notification сотрудникам
        $this->sendEmployeeNotifications($emailFrom, $templateData, $resellerId, $result);

        // Отправка notification клиентам при изменении статуса
        if ($notificationType === self::TYPE_CHANGE && !empty($data['differences']['to'])) {
            $this->sendClientNotifications($emailFrom, $client, $templateData, $resellerId, $data, $result);
        }

        return $result;
    }

    // Добавьте другие методы при необходимости...

    // Validation: Проверка, что resellerId не пуст
    private function validateResellerId(int $resellerId): void
    {
        if (empty($resellerId)) {
            throw new \Exception('Empty resellerId');
        }
    }

    // Validation: Проверка, что notificationType не пуст
    private function validateNotificationType(int $notificationType): void
    {
        if (empty($notificationType)) {
            throw new \Exception('Empty notificationType', 400);
        }
    }

    // Validation: Проверка, что reseller найден
    private function validateReseller(?Seller $reseller): void
    {
        if ($reseller === null) {
            throw new \Exception('Seller not found!', 400);
        }
    }

    // Получение и валидация информации о клиенте
    private function getClient(array $data, int $resellerId): Contractor
    {
        $client = Contractor::getById((int) $data['clientId']);

        if ($client === null || $client->type !== Contractor::TYPE_CUSTOMER || $client->Seller->id !== $resellerId) {
            throw new \Exception('Client not found!', 400);
        }

        return $client;
    }

    // Validation: Проверка, что клиент найден
    private function validateClient(?Contractor $client, int $resellerId): void
    {
        if ($client === null || $client->type !== Contractor::TYPE_CUSTOMER || $client->Seller->id !== $resellerId) {
            throw new \Exception('Client not found!', 400);
        }
    }

    // Определение difference в зависимости от типа уведомления
    private function getDifferences(int $notificationType, array $data): string
    {
        $differences = '';

        if ($notificationType === self::TYPE_NEW) {
            $differences = __('NewPositionAdded', null, $resellerId);
        } elseif ($notificationType === self::TYPE_CHANGE && !empty($data['differences'])) {
            $differences = __('PositionStatusHasChanged', [
                'FROM' => Status::getName((int) $data['differences']['from']),
                'TO' => Status::getName((int) $data['differences']['to']),
            ], $resellerId);
        }

        return $differences;
    }

    // Подготовка данных для шаблона notification
    private function buildTemplateData(array $data, Employee $cr, Employee $et, string $differences, int $resellerId): array
    {
        $cFullName = $data['client']->getFullName();
        if (empty($cFullName)) {
            $cFullName = $data['client']->name;
        }

        return [
            'COMPLAINT_ID' => (int) $data['complaintId'],
            'COMPLAINT_NUMBER' => (string) $data['complaintNumber'],
            'CREATOR_ID' => (int) $data['creatorId'],
            'CREATOR_NAME' => $cr->getFullName(),
            'EXPERT_ID' => (int) $data['expertId'],
            'EXPERT_NAME' => $et->getFullName(),
            'CLIENT_ID' => (int) $data['clientId'],
            'CLIENT_NAME' => $cFullName,
            'CONSUMPTION_ID' => (int) $data['consumptionId'],
            'CONSUMPTION_NUMBER' => (string) $data['consumptionNumber'],
            'AGREEMENT_NUMBER' => (string) $data['agreementNumber'],
            'DATE' => (string) $data['date'],
            'DIFFERENCES' => $differences,
        ];
    }

    // Validation: Проверка, что все данные для шаблона не пусты
    private function validateTemplateData(array $templateData): void
    {
        foreach ($templateData as $key => $tempData) {
            if (empty($tempData)) {
                throw new \Exception("Template Data ({$key}) is empty!", 500);
            }
        }
    }

    // Отправка notification сотрудникам по email
    private function sendEmployeeNotifications(string $emailFrom, array $templateData, int $resellerId, array &$result): void
    {
        $emails = getEmailsByPermit($resellerId, 'tsGoodsReturn');

        if (!empty($emailFrom) && count($emails) > 0) {
            foreach ($emails as $email) {
                MessagesClient::sendMessage([
                    0 => [
                        'emailFrom' => $emailFrom,
                        'emailTo' => $email,
                        'subject' => __('complaintEmployeeEmailSubject', $templateData, $resellerId),
                        'message' => __('complaintEmployeeEmailBody', $templateData, $resellerId),
                    ],
                ], $resellerId, NotificationEvents::CHANGE_RETURN_STATUS);
                $result['notificationEmployeeByEmail'] = true;
            }
        }
    }

    // Отправка уведомлений клиентам
    private function sendClientNotifications(string $emailFrom, Contractor $client, array $templateData, int $resellerId, array $data, array &$result): void
    {
        if (!empty($emailFrom) && !empty($client->email)) {
            MessagesClient::sendMessage([
                0 => [
                    'emailFrom' => $emailFrom,
                    'emailTo' => $client->email,
                    'subject' => __('complaintClientEmailSubject', $templateData, $resellerId),
                    'message' => __('complaintClientEmailBody', $templateData, $resellerId),
                ],
            ], $resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int) $data['differences']['to']);
            $result['notificationClientByEmail'] = true;
        }

        if (!empty($client->mobile)) {
            $res = NotificationManager::send($resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int) $data['differences']['to'], $templateData, $error);
            if ($res) {
                $result['notificationClientBySms']['isSent'] = true;
            }
            if (!empty($error)) {
                $result['notificationClientBySms']['message'] = $error;
            }
        }
    }
}
