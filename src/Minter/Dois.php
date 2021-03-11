<?php

namespace Drupal\doi_crossref\Minter;

use Drupal\persistent_identifiers\MinterInterface;
use Drupal\node\Entity\Node;

/**
 * CrossRef DOI minter.
 */
class Dois implements MinterInterface {

  /**
   * Constructor.
   */
  public function __construct() {
    $config = \Drupal::config('doi_crossref.settings');
    $this->api_endpoint = $config->get('doi_crossref_api_endpoint');
    $this->doi_prefix = $config->get('doi_crossref_prefix');
    $this->doi_suffix_source = $config->get('doi_crossref_suffix_source');
    $this->doi_suffix_prefix = $config->get('doi_crossref_suffix_prefix');
    $this->api_username = $config->get('doi_crossref_username');
    $this->api_password = $config->get('doi_crossref_password');
  }

  /**
   *
   */
  public function getResourceTypes() {
    return [
      'Books and Chapters' => 'Books and Chapters',
      'Conference Proceedings' => 'Conference Proceedings',
      'Datasets' => 'Datasets',
      'Dissertations' => 'Dissertations',
      'Journals and Articles' => 'Journals and Articles',
      'Peer Reviews' => 'Peer Reviews',
      'Posted Content' => 'Posted Content',
      'Reports and Working Papers' => 'Reports and Working Papers',
      'Standards' => 'Standards',
    ];
  }

  /**
   * Returns the minter's name.
   *
   * @return string
   *   Appears in the Persistent Identifiers config form.
   */
  public function getName() {
    return t('CrossRef DOI');
  }

  /**
   * Returns the minter's type.
   *
   * @return string
   *   Appears in the entity edit form next to the checkbox.
   */
  public function getPidType() {
    return t('CrossRef DOI');
  }

  /**
   * Mints the identifier.
   *
   * @param object $entity
   *   The node, etc.
   * @param mixed $extra
   *   Extra data the minter needs, for example from the node edit form.
   *
   * @return string
   *   The DOI that will be saved in the persister's designated field.
   */
  public function mint($entity, $extra = NULL) {
    if ($this->doi_suffix_source == 'id') {
      $suffix = $entity->id();
    }
    if ($this->doi_suffix_source == 'uuid') {
      $suffix = $entity->Uuid();
    }
    if ($this->doi_suffix_source == 'timerand') {
      $time = time();
      $rand = bin2hex(random_bytes(2));
      $timerand = $time . '.' . $rand;
      $suffix = $timerand;
    }
    if ($this->doi_suffix_prefix != '') {
      $suffix = $this->doi_suffix_prefix . $suffix;
    }
    $doi = $this->doi_prefix . $suffix;

    $crossref_xml = $this->createCrossrefXml($entity->id(), $doi);

    // Programmatically generate the CrossRef XML first
    //$success = $this->postToApi($doi, $crossref_xml);

    return $doi;
  }

  /**
   * Creates XML to send to the CrossRef API.
   *
   * @param string $nid
   *   The ID of the node to be described.
   * @param string $doi
   *   The DOI.
   * @return str 
   *   String of CrossRef XML.
   */
  public function createCrossrefXml($nid, $doi) {
    $node = Node::load($nid);
    $path = \Drupal::service('file_system')->realpath(\Drupal::service('module_handler')->getModule('doi_crossref')->getPath());
    $dataset_template_path = $path . "/templates/dataset.template.xml";
    $dataset_template_string = file_get_contents($dataset_template_path);
    $dataset_submission = str_replace('_BATCH_ID_', "LDBASE-" . $node->uuid(), $dataset_template_string);
    $dataset_submission = str_replace('_TIMESTAMP_', time(), $dataset_submission);
    $dataset_submission = str_replace('_TITLE_', $node->getTitle(), $dataset_submission);
    $dataset_submission = str_replace('_DOI_', $doi, $dataset_submission);
    $dataset_submission = str_replace('_URL_', \Drupal\Core\Url::fromRoute('entity.node.canonical', ['node' => $nid], ['absolute' => TRUE])->toString(), $dataset_submission);
    return $dataset_submission;
  }


  /**
   * POSTs the XML to the CrossRef API.
   *
   * @param string $doi
   *   The DOI.
   * @param string $crossref_xml
   *   The CrossRef XML.
   *
   * @return bool
   *   TRUE if successful, FALSE if not.
   */
  public function postToApi($doi, $crossref_xml) {
    $drupal_file_path = file_unmanaged_save_data($crossref_xml);
    $real_file_path = \Drupal::service('file_system')->realpath($drupal_file_path);
    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => $this->api_endpoint,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => "POST",
      CURLOPT_POSTFIELDS => array(
        'operation' => 'doMDUpload', 
        'login_id' => $this->api_username,
        'login_passwd' => $this->api_password,
        'fname'=> new CURLFILE($real_file_path)),
    ));
    $response = curl_exec($curl);
    curl_close($curl);
    file_unmanaged_delete($drupal_file_path);
  }
}
