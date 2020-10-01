<?php

/**
 * @package		Documentov
 * @author		Roman V Zhukov
 * @copyright           Copyright (c) 2018 Andrey V Surov, Roman V Zhukov (https://www.documentov.com/)
 * @license		https://opensource.org/licenses/GPL-3.0
 * @link		https://www.documentov.com
 */
/*
 *  Действие таймер. Таймер позволяет произвести запись в определенное поле документа (либо настроечное поле) в определенное время.
 *  Таймер должен быть привязан как к конкретному документу.
 *  Таймер можно отключить, можно включить. Для выключения, необходимо указать, где брать UUID документа (документов) и идентификатор таймера. UUID можно брать из текущего документа, либо из ссылочного поля. После записи в документа, срабатывает контекст активности.
 *  Идентификаторы таймеров выбираются из списка. Количество таймеров можно задавать в настройках действия.
 */

class ControllerExtensionActionTimer extends ActionController
{

  const ACTION_INFO = array(
    'name' => 'timer',
    'inRouteContext' => true,
    'inRouteButton' => true,
    'inFolderButton' => true
  );

  public function index()
  {
    $this->load->language('extension/action/timer');

    $data['cancel'] = $this->url->link('marketplace/extension', 'type=action', true);

    $this->response->setOutput($this->load->view('extension/action/timer', $data));
  }

  public function install()
  {
    $this->db->query("CREATE TABLE `action_timer` (`identifier` varchar(255) NOT NULL, `document_uid` varchar(36) NOT NULL, `task_id` int(11) NOT NULL) ENGINE=MyISAM DEFAULT CHARSET=utf8;");
  }

  public function uninstall()
  { }

  /**
   * Метод возвращает описание действия, исходя из параметров
   */
  public function getDescription($params)
  {
    $this->load->language('action/timer');
    $this->load->model('doctype/doctype');
    $doclink_field_name = "";
    $identifier_field_name = "";
    $result = "";

    if (!empty($params['timer_doclink_field_uid']) && $params['timer_doclink_field_uid'] !== '0') {
      $doclink_field_name = $this->model_doctype_doctype->getField($params['timer_doclink_field_uid'])['name'];
    }

    if (!empty($params['identifier_field_uid'])) {
      $identifier_field_name = $this->model_doctype_doctype->getField($params['identifier_field_uid'])['name'];
    }

    switch ($params['timer_action']) {
      case "0":
        $exectime_field_name = "";
        if (!empty($params['exectime_field_uid'])) {
          $exectime_field_name = $this->model_doctype_doctype->getField($params['exectime_field_uid'])['name'];
        }

        if ($doclink_field_name === "") {
          if ($identifier_field_name === "") {
            $result = sprintf($this->language->get('text_description_start_3'), $exectime_field_name);
          } else {
            $result = sprintf($this->language->get('text_description_start_1'), $exectime_field_name, $identifier_field_name);
          }
        } else {
          if ($identifier_field_name === "") {
            $result = sprintf($this->language->get('text_description_start_4'), $doclink_field_name, $exectime_field_name);
          } else {
            $result = sprintf($this->language->get('text_description_start_2'), $doclink_field_name, $exectime_field_name, $identifier_field_name);
          }
        }
        break;
      case "1":
        if ($doclink_field_name === "") {
          $result = sprintf($this->language->get('text_description_stop_1'), $identifier_field_name);
        } else {
          $result = sprintf($this->language->get('text_description_stop_2'), $doclink_field_name, $identifier_field_name);
        }
        break;
      case "2":
        if ($doclink_field_name === "") {
          $result = sprintf($this->language->get('text_description_stop_all_1'));
        } else {
          $result = sprintf($this->language->get('text_description_stop_all_2'), $doclink_field_name);
        }
        break;
    }
    return $result;
  }

  /**
   * Метод возвращает форму действия для типа документа
   * @param type $data - массив, включающий doctype_uid, route_uid
   */
  public function getForm($data)
  {
    $this->load->language('action/timer');
    $this->load->language('doctype/doctype');
    $lang_id = (int) $this->config->get('config_language_id');

    if (empty($data['action']['timer_doclink_field_uid'])) {
      $data['action']['timer_doclink_field_uid'] = "0";
      $data['timer_doclink_field_name'] = $this->language->get('text_currentdoc');
    } else {
      $timer_doclink_field = $this->model_doctype_doctype->getField($data['action']['timer_doclink_field_uid']);
      $timer_doclink_field_name = $this->language->get('text_by_link_in_field') . ' &quot' . $timer_doclink_field['name'] . '&quot';
      $data['timer_doclink_field_name'] = $timer_doclink_field_name;
      $data['timer_doclink_field_setting'] = $timer_doclink_field['setting'];
    }

    if (!empty($data['action']['exectime_field_uid'])) {
      $exectime_field_description = $this->model_doctype_doctype->getField($data['action']['exectime_field_uid']);
      $data['exectime_field_name'] = $exectime_field_description['name'];
    }

    if (!empty($data['action']['identifier_field_uid'])) {
      $identifier_field_description = $this->model_doctype_doctype->getField($data['action']['identifier_field_uid']);
      $identifier_field_type = $identifier_field_description['type'];
      $identifier_field_doctype_uid = $identifier_field_description['doctype_uid'];
      $doctypename = $this->model_doctype_doctype->getDoctypeDescriptions($identifier_field_doctype_uid)[$lang_id]['name'];
      if (strcmp($data['action']['timer_doclink_field_uid'], '0') === 0) {
        $data['identifier_field_name'] = $identifier_field_description['name'];
      } else {
        $data['identifier_field_name'] = $doctypename . ' - ' . $identifier_field_description['name'];
      }
    } else {
      $data['action']['identifier_field_uid'] = "0";
      //$data['identifier_field_name'] = $this->language->get('text_none');
    }

    if (!empty($data['action']['target_field_uid'])) {
      $target_field_description = $this->model_doctype_doctype->getField($data['action']['target_field_uid']);
      $target_field_type = $target_field_description['type'];
      $target_field_doctype_uid = $target_field_description['doctype_uid'];
      $doctypename = $this->model_doctype_doctype->getDoctypeDescriptions($target_field_doctype_uid)[$lang_id]['name'];
      if (strcmp($data['action']['timer_doclink_field_uid'], '0') === 0) {
        $data['target_field_name'] = $target_field_description['name'];
      } else {
        $data['target_field_name'] = $doctypename . ' - ' . $target_field_description['name'];
      }

      /* $data['avaliable_setters'] = $this->load->controller('extension/field/' . $target_field_type . '/getFieldMethods', 'setter');
              if (!empty($data['action']['target_field_setter'])) {
              $method_info = array(
              'method' => $data['action']['target_field_setter'],
              'field_uid' => $data['action']['target_field_uid']
              );
              if (!empty($data['action']['method'][$data['action']['target_field_setter']])) {
              $method_info['method_params'] = $data['action']['method'][$data['action']['target_field_setter']];
              }
              $data['setter_form'] = $this->load->controller('extension/field/' . $target_field_type . '/getFieldMethodForm', $method_info);
              } */
    }

    if (!empty($data['action']['document_route_uid'])) {
      $route_info = $this->model_doctype_doctype->getRoute($data['action']['document_route_uid']);
      $doctype_name = '';
      if ($data['action']['timer_doclink_field_uid'] !== "0") {
        $doctype_uid = $route_info['doctype_uid'];
        $language_id = $this->config->get('config_language_id');
        $doctype_info = $this->model_doctype_doctype->getDoctypeDescriptions($doctype_uid)[$language_id];
        $doctype_name = $doctype_info['name'] . ' - ';
      }
      $data['document_route_name'] = $doctype_name . (isset($route_info['name']) ? $route_info['name'] : "");
    }

    return $this->load->view('action/timer/timer_form', $data);
  }

  /**
   * Метод позволяет изменить сохраняемые в базу параметры действия (при необходимости)
   * @param type $data
   * @return type
   */
  public function setParams($data)
  {
    return $data['params']['action'];
  }

  /**
   * Возвращает неизменяемую информацию о действии
   * @return array()
   */
  public function getActionInfo()
  {
    return $this::ACTION_INFO;
  }

  /**
   * 
   * @param type $data  = array('document_uid', 'button_uid', 'params');
   */
  public function executeButton($data)
  {
    $this->load->language('extension/action/record');
    if (isset($data['document_uid'])) { //есть document_uid - запуск действия из документ
      $this->executeRoute($data);
      $result = array(
        'reload' => str_replace('&amp;', '&', $this->url->link('document/document', 'document_uid=' . $data['document_uid'] . '&_=' . rand(100000000, 999999999))),
        'log' => "" //если Запись привязана к кнопке, то лог о том в какое поле что было записано, скорее всего, будет неуместен, т.к. запись является второстепенным событие (первостепенным - нажатие на саму кнопку)
      );
    } else { //запуск из журнала
      foreach ($data['document_uids'] as $document_uid) {
        $data['document_uid'] = $document_uid;
        $this->executeRoute($data);
      }
      $result = array(
        'reload' => 'table',
        'log' => ""
      );
    }

    return ($result);
  }

  /**
   * 
   * @param type $data  = array('document_uid', 'button_uid', 'params');
   */
  public function executeRoute($data)
  {
    $this->load->language('extension/action/timer');
    $external_data = $data['params'];
    $this->load->model('daemon/queue');
    $this->load->model('extension/action/timer');
    $this->load->model('document/document');
    $document_uid = $data['document_uid'];

    $identifier = null;
    $log = "";
    if ($data['params']['timer_doclink_field_uid'] !== "0") {
      //документ, к которому будет привязан таймер, брать по ссылке из поля
      $timer_document_uids = explode(',', $this->model_document_document->getFieldValue($data['params']['timer_doclink_field_uid'], $document_uid, $draft = FALSE));
    } else {
      $timer_document_uids = array($document_uid);
    }

    if (!empty($data['params']['identifier_field_uid']) && $data['params']['identifier_field_uid'] !== "0") {
      $identifier = $this->model_document_document->getFieldValue($data['params']['identifier_field_uid'], $document_uid);
    }

    $start_time_string = $this->model_document_document->getFieldValue($data['params']['exectime_field_uid'], $document_uid);


    switch ($data['params']['timer_action']) {
      case "0":
        //включение таймера
        $dbformat = 'Y-m-d H:i:s';
        if ($data['params']['exectime_field_uid'] === "") {
          //не задано поле с датой срабатывания, ничего не делаем
          return;
        }
        $external_data = array();
        // if ($identifier || $identifier == "0") {
        if ($data['params']['target_field_uid'] !== "") {
          $external_data['target_field_uid'] = $data['params']['target_field_uid'];
        }
        // }

        if (isset($data['params']['document_route_uid'])) {
          $external_data['document_route_uid'] = $data['params']['document_route_uid'];
        }
        if ($start_time_string !== "" && date_create($start_time_string)) {
          $start_time = new DateTime($start_time_string);
          foreach ($timer_document_uids as $timer_document_uid) {
            $external_data['timer_document_uid'] = $timer_document_uid;
            if (!is_null($identifier)) {
              //удаление предыдущих задач для таймера с заданным документом и идентификатором
              $task_ids = $this->model_extension_action_timer->getTimerTaskIDs($timer_document_uid, $identifier);
              if (!empty($task_ids)) {
                foreach ($task_ids as $task_id) {
                  $this->model_daemon_queue->deleteTask($task_id);
                }
              }
              $external_data['identifier'] = $identifier;
              $task_id = $this->model_daemon_queue->addTask('extension/action/timer/executeDeferred', $external_data, 1, $start_time->format($dbformat));
              $this->model_extension_action_timer->setTimer($timer_document_uid, $task_id, $identifier);
              $start_time->add(new DateInterval("PT3S"));
            } else {
              $task_id = $this->model_daemon_queue->addTask('extension/action/timer/executeDeferred', $external_data, 1, $start_time->format($dbformat));
              $this->model_extension_action_timer->setTimer($timer_document_uid, $task_id);
            }
          }
        } else {
          $log = $log . " " . $timer_document_uid . " - " . $this->language->get("text_not_able_to_get_datetime");
        }

        break;
      case "1":
        //выключение таймера
        if (!is_null($identifier)) {
          foreach ($timer_document_uids as $timer_document_uid) {
            $task_ids = $this->model_extension_action_timer->getTimerTaskIDs($timer_document_uid, $identifier);
            if (!is_null($task_ids)) {
              foreach ($task_ids as $task_id) {
                $this->model_daemon_queue->deleteTask($task_id);
              }
            } else {
              $log = $log . " " . $timer_document_uid . " - " . $this->language->get("text_no_task_in_queue");
            }
            $this->model_extension_action_timer->unsetTimer($timer_document_uid, $identifier);
          }
        }
        break;
      case "2":
        //выключение всех таймеров, привязанных к документу
        foreach ($timer_document_uids as $timer_document_uid) {
          $task_ids = $this->model_extension_action_timer->getTimerTaskIDs($timer_document_uid);
          if (!is_null($task_ids)) {
            foreach ($task_ids as $task_id) {
              $this->model_daemon_queue->deleteTask($task_id);
            }
          }
          $this->model_extension_action_timer->unsetTimer($timer_document_uid);
        }
        break;
    }

    return array(
      'log' => $log
    );
  }

  public function executeDeferred($data)
  {
    $this->load->model('extension/action/timer');
    $this->load->model('document/document');
    if (isset($data['timer_document_uid'])) {
      // проверяем наличие документа, вдруг, удалили
      $timer_document_info = $this->model_document_document->getDocument($data['timer_document_uid'], false);
      if (!$timer_document_info) {
        $this->model_extension_action_timer->unsetTimer($data['timer_document_uid']);
      } else if (isset($data['identifier'])) {
        if (!empty($data['target_field_uid'])) {
          $this->model_document_document->editFieldValue($data['target_field_uid'], $data['timer_document_uid'], $data['identifier']);
        }
        $this->model_extension_action_timer->unsetTimer($data['timer_document_uid'], $data['identifier']);
      }
      if (!empty($data['document_route_uid'])) {
        $this->model_document_document->moveRoute($data['timer_document_uid'], $data['document_route_uid']);
        $params = array("document_uid" => $data['timer_document_uid'], "context" => 'jump');
        $this->load->controller("document/document/route_cli", $params);
      }
      //повторная проверка на наличие документа после отрабатывания маршрута
      $timer_document_info = $this->model_document_document->getDocument($data['timer_document_uid'], false);
      if (!$timer_document_info) {
        $this->model_extension_action_timer->unsetTimer($data['timer_document_uid']);
      }
    }
  }
}
