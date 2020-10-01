<?php

/**
 * @package		Documentov
 * @author		Roman V Zhukov
 * @copyright           Copyright (c) 2018 Andrey V Surov, Roman V Zhukov (https://www.documentov.com/)
 * @license		https://opensource.org/licenses/GPL-3.0
 * @link		https://www.documentov.com
 */
class ControllerDaemonQueue extends Controller
{

  public function index()
  {
  }

  public function runTask($task_id)
  {
    $this->load->model('daemon/queue');

    if ($task_id > 0 && $task_id != "service") {
      //получили номер задачи
      $sql = "SELECT * FROM " . DB_PREFIX . "daemon_queue WHERE "
        . "task_id = '" . (int) $task_id . "'";
      $query = $this->db->query($sql);
      if (!$query->num_rows) {
        print(" TASK: " . $task_id . " not found");
      } else {
        $action = $query->row['action'];

        $params = @unserialize($query->row['action_params']);
        if ($params === FALSE) {
          $params = @json_decode($query->row['action_params'], true);
        }
        $exec_attempt = (int) $query->row['exec_attempt'];
        $exec_attempt++;
        $this->db->query("UPDATE " . DB_PREFIX . "daemon_queue SET "
          . "start_time=NOW(), "
          . "status=1, "
          . "exec_attempt=" . $exec_attempt . ", "
          . "pid=" . getmypid() . " "
          . "WHERE task_id=" . (int) $task_id);

        try {
          // throw new Exception("Ой!");
          $this->load->controller($action, $params);
          $this->db->query("DELETE FROM " . DB_PREFIX . "daemon_queue WHERE "
            . "task_id=" . (int) $task_id);
        } catch (Exception $e) {
          print(" TASK: " . $task_id . " Exception in " . $action . ": " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        } catch (Error $e) {
          print(" TASK: " . $task_id . " Exception in " . $action . ": " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }
      }
    } else {
      //запускаем сервисную задачу
      $this->runServiceTask();
    }
    // $logfile = DIR_STORAGE . "logs/daemontask2.log";
    // $fp = fopen($logfile, 'a');
    // fwrite($fp, ob_get_contents());
    // fclose($fp);
    // ob_end_clean();
    // echo "END";
  }

  private function resetProcessStarted($process)
  {
    echo sprintf($this->language->get("text_kill_daemon_process"), $process);
    $this->model_setting_variable->setVar($process . "_started", 0);
  }

  private function runServiceTask()
  {
    $this->load->model('setting/variable');
    $this->load->model('doctype/doctype');
    $this->load->model('tool/utils');
    $this->load->language('daemon/queue');
    date_default_timezone_set($this->config->get('date.timezone'));

    //проверям на запущенный процесс актуализации (либо полнотекстовой индексации)
    $processes = ['subscription', 'ft_indexer', 'ft_createindex', 'ft_deleteindex'];
    foreach ($processes as $proc) {
      if (!empty($this->variable->get($proc . '_started'))) {
        // процесс запущен, проверяем время работы
        // это время фиксируется в последнем обработанном поле
        $last_field_proc = $this->variable->get($proc . '_last_field');
        if (!$last_field_proc) {
          // последнее поле пустое, сбрасываем признак активности
          $this->resetProcessStarted($proc);
          return;
        }
        $last_time_proc = $this->variable->get($proc . '_time_' .  $last_field_proc);
        if (!$last_time_proc) {
          $this->resetProcessStarted($proc);
          return;
        }
        $now_time = new DateTime();
        $diff = $now_time->getTimestamp() - strtotime($last_time_proc);
        if ($diff / 60 > 5) {
          // со времени запуска прошло 5 мину
          $this->resetProcessStarted($proc);
          return;
        }
      }
    }


    if (
      !empty($this->variable->get('subscription_started')) ||
      !empty($this->variable->get('ft_indexer_started')) ||
      !empty($this->variable->get('ft_createindex_started')) ||
      !empty($this->variable->get('ft_deleteindex_started'))
    ) {
      return;
    }
    //получаем установленные поля
    $this->load->model('setting/extension');
    $fields = $this->model_setting_extension->getInstalled('field');
    $priority_fields = array(); //поля, которые будут обрабатываться при каждом запуске
    foreach (array('string') as $f) {
      if (array_search($f, $fields) !== FALSE) {
        $priority_fields[] = $f; //после установлено, добавляем его в приоритетные
      }
    }
    //АКТУАЛИЗАЦИЯ ДАННЫХ по подписке
    if (!$this->subscriptionTask($fields)) {
      if (!$this->FTIndexerTask($fields)) {
        if (!$this->FTCreateIndexTask($fields)) {
          $this->FTDeleteIndexTask($fields);
        };
      };
    };
  }

  private function subscriptionTask($fields)
  {
    $priority_fields = array(); //поля, которые будут обрабатываться при каждом запуске
    foreach (array('string') as $f) {
      if (array_search($f, $fields) !== FALSE) {
        $priority_fields[] = $f; //после установлено, добавляем его в приоритетные
      }
    }
    $this->model_setting_variable->setVar("subscription_started", 1);
    //получаем тип поля для актуализации     
    $result = false;
    $time = new DateTime('now');
    $now = $time->format('Y-m-d H:i:s');
    try {
      $last_field = $this->variable->get('subscription_last_field');
      $key = array_search($last_field, $fields) ?? 0;
      $i = 0;
      do {
        $field = $fields[++$key] ?? $fields[$i++];
      } while ($field == "" || array_search($field, $priority_fields) !== FALSE);

      //получаем время последней проверки
      $last_time = $this->variable->get('subscription_time_' . $field) ?? $now;

      //записываем последнее проверенное поле
      $this->model_setting_variable->setVar("subscription_last_field", $field);
      //записываем время проверки поля
      $this->model_setting_variable->setVar("subscription_time_" . $field, $now);

      //получаем измененные поля, на которые есть подписка
      $subscriptions = $this->model_daemon_queue->getFieldChangeSubscriptions($field, $last_time, $now);
      foreach ($priority_fields as $pf) {
        $subscriptions = array_merge($subscriptions, $this->model_daemon_queue->getFieldChangeSubscriptions($pf, $this->variable->get('subscription_time_' . $pf) ?? $now, $now));
        $this->model_setting_variable->setVar("subscription_time_" . $pf, $now);
      }
      if ($subscriptions) {
        $this->load->model('doctype/doctype');

        $fields = array();
        foreach ($subscriptions as $subscription) {
          $fields[$subscription['subscription_field_uid']][] = $subscription['subscription_document_uid'];
        }
        foreach ($fields as $field_uid => $documents) {
          $field_info = $this->model_doctype_doctype->getField($field_uid);
          if (empty($field_info['type'])) {
            $this->model_doctype_doctype->delSubscription($field_uid);
            continue;
          }
          $documents = array_unique($documents);
          print_r(" SERVICE TASK SUBSCRIPTION ON CHANGE: Actualized field " . $field_info['type'] . " (ID=" . $field_uid . ") by subscription ");
          $model = "model_extension_field_" . $field_info['type'];
          $this->load->model('extension/field/' . $field_info['type']);
          echo $this->$model->subscription($field_uid, $documents);
          $result = true;
        };
      }
    } catch (Exception $e) {
      print_r(" SERVICE TASK SUBSCRIPTION ON CHANGE: " . $e->getMessage() . " ");
    } finally {
      //завершаем процесс
      $this->model_setting_variable->setVar("subscription_started", 0);
    }
    return $result;
  }

  private function FTIndexerTask($fields)
  {
    $this->load->model('document/search');
    $this->model_setting_variable->setVar("ft_indexer_started", 1);
    $result = false;
    $time = new DateTime('now');
    $now = $time->format('Y-m-d H:i:s');
    try {
      $last_field = $this->variable->get('ft_indexer_last_field');
      $key = array_search($last_field, $fields) ?? -1;
      if ($key < (count($fields) - 1)) {
        ++$key;
      } else {
        $key = 0;
      }
      $field = $fields[$key];
      //получаем время последней проверки
      $last_time = $this->variable->get('ft_indexer_time_' . $field) ?? $now;
      //записываем последнее проверенное поле
      $this->model_setting_variable->setVar("ft_indexer_last_field", $field);
      //записываем время проверки поля
      $this->model_setting_variable->setVar("ft_indexer_time_" . $field, $now);
      //получаем измененные поля, которые включены в полнотекстовый индекс у которых индекс уже сформирован
      $ft_indexer_fields = $this->model_document_search->getFTIndexerFieldChange($field, $last_time, $now);
      if ($ft_indexer_fields) {
        $this->load->model('doctype/doctype');

        $fields = array();
        foreach ($ft_indexer_fields as $ft_indexer_field) {
          $fields[$ft_indexer_field['field_uid']][] = $ft_indexer_field['document_uid'];
        }
        foreach ($fields as $field_uid => $documents) {
          /*print_r("FIELD_UID: " + $field_uid);
                    print_r($documents);*/
          $field_info = $this->model_doctype_doctype->getField($field_uid);
          if (empty($field_info['type'])) {
            continue;
          }
          $documents = array_unique($documents);
          print_r(" SERVICE TASK FTSEARCH ON CHANGE: indexing field " . $field_info['type'] . " (ID=" . $field_uid . ")");
          $model = "model_extension_field_" . $field_info['type'];
          $this->load->model('extension/field/' . $field_info['type']);
          foreach ($documents as $document_uid) {
            $ft_index_value =  $this->$model->get_ftsearch_index($field_uid, $document_uid);
            $this->model_document_search->setIndex($field_uid, $document_uid, $ft_index_value);
          }
          $result = true;
        };
      }
    } catch (Exception $e) {
      print_r(" SERVICE TASK FTSEARCH ON CHANGE: " . $e->getMessage() . " ");
    } catch (Error $e) {
      print_r(" SERVICE TASK FTSEARCH ON CHANGE: " . $e->getMessage() . " ");
    } finally {
      //завершаем процесс
      $this->model_setting_variable->setVar("ft_indexer_started", 0);
    }
    return $result;
  }

  private function FTCreateIndexTask($fields)
  {
    $this->load->model('document/search');
    $this->model_setting_variable->setVar("ft_createindex_started", 1);
    $result = false;
    $time = new DateTime('now');
    $now = $time->format('Y-m-d H:i:s');
    try {
      $last_field = $this->variable->get('ft_createindex_last_field');
      $key = array_search($last_field, $fields) ?? -1;
      if ($key < (count($fields) - 1)) {
        ++$key;
      } else {
        $key = 0;
      }
      $field = $fields[$key];
      //получаем время последней проверки
      $last_time = $this->variable->get('ft_createindex_time_' . $field) ?? $now;
      //записываем последнее проверенное поле
      $this->model_setting_variable->setVar("ft_createindex_last_field", $field);
      //записываем время проверки поля
      $this->model_setting_variable->setVar("ft_createindex_time_" . $field, $now);

      //получаем измененные поля, которые включены в полнотекстовый индекс и у которых индекс еще не сформирован
      $ft_indexer_fields_noindex = $this->model_document_search->getFTIndexerFieldWithNoIndex($field);

      if ($ft_indexer_fields_noindex) {
        $this->load->model('doctype/doctype');

        $fields = array();
        foreach ($ft_indexer_fields_noindex as $ft_indexer_field) {
          $fields[$ft_indexer_field['field_uid']][] = $ft_indexer_field['document_uid'];
        }
        foreach ($fields as $field_uid => $documents) {
          /*print_r("FIELD_UID: " + $field_uid);
                    print_r($documents);*/
          $field_info = $this->model_doctype_doctype->getField($field_uid);
          if (empty($field_info['type'])) {
            continue;
          }
          $documents = array_unique($documents);
          print_r(" SERVICE TASK FTSEARCH ON CREATE INDEX: creating index field " . $field_info['type'] . " (ID=" . $field_uid . ")");
          $model = "model_extension_field_" . $field_info['type'];
          $this->load->model('extension/field/' . $field_info['type']);
          foreach ($documents as $document_uid) {
            $ft_index_value =  $this->$model->get_ftsearch_index($field_uid, $document_uid);
            /*print_r("FTINDEX:");
                        print_r($ft_index_value);*/
            $this->model_document_search->setIndex($field_uid, $document_uid, $ft_index_value);
          }
          $result = true;
        };
      }
    } catch (Exception $e) {
      print_r(" SERVICE TASK FTSEARCH ON CREATE INDEX: " . $e->getMessage() . " ");
    } catch (Error $e) {
      print_r(" SERVICE TASK FTSEARCH ON CREATE INDEX: " . $e->getMessage() . " ");
    } finally {
      //завершаем процесс
      $this->model_setting_variable->setVar("ft_createindex_started", 0);
    }
    return $result;
  }

  private function FTDeleteIndexTask($fields)
  {
    $this->load->model('document/search');
    $this->model_setting_variable->setVar("ft_deleteindex_started", 1);
    $result = false;
    $time = new DateTime('now');
    $now = $time->format('Y-m-d H:i:s');
    try {
      $last_field = $this->variable->get('ft_deleteindex_last_field');
      $key = array_search($last_field, $fields) ?? -1;
      if ($key < (count($fields) - 1)) {
        ++$key;
      } else {
        $key = 0;
      }
      $field = $fields[$key];
      //получаем время последней проверки
      $last_time = $this->variable->get('ft_deleteindex_time_' . $field) ?? $now;
      //записываем последнее проверенное поле
      $this->model_setting_variable->setVar("ft_deleteindex_last_field", $field);
      //записываем время проверки поля
      $this->model_setting_variable->setVar("ft_deleteindex_time_" . $field, $now);

      //удаляем индекс полей, исключенных из полнотекстового индекса
      $this->model_document_search->FTIndexerDeleteExcluded($field);
      $result = true;
    } catch (Exception $e) {
      print_r(" SERVICE TASK FTSEARCH ON DELETE INDEX: " . $e->getMessage() . " ");
    } catch (Error $e) {
      print_r(" SERVICE TASK FTSEARCH ON DELETE INDEX: " . $e->getMessage() . " ");
    } finally {
      //завершаем процесс
      $this->model_setting_variable->setVar("ft_deleteindex_started", 0);
    }
    return $result;
  }

  /**
   * Метод для запуска актуализации файлового кэша
   */
  public function runFileCacheRefreshTask($data)
  {
    if (!$data) {
      return;
    }
    $result = $this->cache->save($data);
    // if ($result) {
    //   print_r(" added " . implode(", ", $result) . " ");
    // }
  }

  /**
   * Метод для создания кэша
   * $data = [
   *           'model' => 'document/document',
   *           'method' => 'getDocuments',
   *           'params' => $data
   *         ];
   */
  public function runFileCacheAppendTask($data)
  {
    if (empty($data['model']) || empty($data['method'])) {
      return;
    }
    // echo " " . $data['model'] . "/" . $data['method'] . "/" . ($data['params']['doctype_uid'] ?? "");
    $model = "model_" . str_replace('/', '_', $data['model']);
    $this->load->model($data['model']);
    $method = $data['method'];
    $this->$model->$method($data['params']);
  }
}
