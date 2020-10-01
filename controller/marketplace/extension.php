<?php
class ControllerMarketplaceExtension extends Controller
{
  private $error = array();

  public function index()
  {
    $this->load->model('account/customer');
    $this->model_account_customer->setLastPage($this->url->link('marketplace/extension', '', true, true));
    $this->load->language('marketplace/extension');

    $this->document->setTitle($this->language->get('heading_title'));

    if (isset($this->request->get['type'])) {
      $data['type'] = $this->request->get['type'];
    } else {
      $data['type'] = '';
    }

    $data['categories'] = array();

    $files = glob(DIR_APPLICATION . 'controller/extension/extension/*.php', GLOB_BRACE);

    foreach ($files as $file) {
      $extension = basename($file, '.php');

      // Compatibility code for old extension folders
      $this->load->language('extension/extension/' . $extension, 'extension');

      $files = glob(DIR_APPLICATION . 'controller/extension/' . $extension . '/*.php', GLOB_BRACE);

      $data['categories'][] = array(
        'code' => $extension,
        'text' => $this->language->get('extension')->get('heading_title') . ' (' . count($files) . ')',
        'href' => $this->url->link('extension/extension/' . $extension, '', true)
      );
    }

    $data['header'] = $this->load->controller('common/header');
    $data['column_left'] = $this->load->controller('common/column_left');
    $data['footer'] = $this->load->controller('common/footer');

    $this->response->setOutput($this->load->view('marketplace/extension', $data));
  }
}
