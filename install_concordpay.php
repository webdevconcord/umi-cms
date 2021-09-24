<?php

$className = "concordpay";
$paymentName = "Concordpay";

include "standalone.php";

// Тип для внутреннего объекта, связанного с публичным типом
$objectTypesCollection = umiObjectTypesCollection::getInstance();
$internalTypeId = $objectTypesCollection->getTypeIdByGUID("emarket-paymenttype");

$sel = new selector('objects');
$sel->types('object-type')->id($internalTypeId);
$sel->where('class_name')->equals($className);
$sel->limit(0, 1);

$bAdd = $sel->length() == 0;

if($bAdd) {
    $objectsCollection = umiObjectsCollection::getInstance();

    // получаем родительский тип
    $parentTypeId = $objectTypesCollection->getTypeIdByGUID("emarket-payment");

    $typeId = $objectTypesCollection->addType($parentTypeId, $paymentName);
    $objectType = $objectTypesCollection->getType($typeId);

    $groupdId = $objectType->addFieldsGroup('settings', 'Параметры', true, true);
    $group = $objectType->getFieldsGroupByName('settings');

    $fieldsCollection = umiFieldsCollection::getInstance();

    $fieldTypesCollection = umiFieldTypesCollection::getInstance();
    $typeBoolean = $fieldTypesCollection->getFieldTypeByDataType('boolean')->getId();
    $typeString = $fieldTypesCollection->getFieldTypeByDataType('string')->getId();
    $typeRelation = $fieldTypesCollection->getFieldTypeByDataType('relation')->getId();

    $merchantFieldId = $fieldsCollection->addField('merchant_id', 'Идентификатор продавца', $typeString);
    $fieldMerchant = $fieldsCollection->getField($merchantFieldId);
    $fieldMerchant->setIsRequired(true);
    $fieldMerchant->setIsInSearch(false);
    $fieldMerchant->setIsInFilter(false);
    $fieldMerchant->commit();
    $group->attachField($merchantFieldId);

    $secretKeyFieldId = $fieldsCollection->addField('secret_key', 'Секретный ключ', $typeString);
    $fieldSecretKey = $fieldsCollection->getField($secretKeyFieldId);
    $fieldSecretKey->setIsRequired(true);
    $fieldSecretKey->setIsInSearch(false);
    $fieldSecretKey->setIsInFilter(false);
    $fieldSecretKey->commit();
    $group->attachField($secretKeyFieldId);

    // Создаем внутренний объект
    $internalObjectId = $objectsCollection->addObject($paymentName, $internalTypeId);
    $internalObject = $objectsCollection->getObject($internalObjectId);
    $internalObject->setValue("class_name", $className); // имя класса для реализации

    // связываем его с типом
    $internalObject->setValue("payment_type_id", $typeId);
    $internalObject->setValue("payment_type_guid", "user-emarket-payment-" . $typeId);
    $internalObject->commit();

    // Связываем внешний тип и внутренний объект
    $objectType = $objectTypesCollection->getType($typeId);
    $objectType->setGUID($internalObject->getValue("payment_type_guid"));
    $objectType->commit();

    echo "Готово!";
} else {
    echo "Способ оплаты с классом $className уже существует";
}


/**
 * Возвращаем Id справочника статусов заказа
 * @return mixed
 * @throws selectorException
 */
function getGuidOrderStatusesId()
{
    $sel = new selector('objects');
    $sel->types('object-type')->name('emarket', 'order_status');
    $sel->option('no-length')->value(true);
    return $sel->first->getTypeId();
}
