<?php

namespace CreativeMail\Modules\Contacts\Handlers;

define('CE4WP_CF7_EVENTTYPE', 'WordPress - Contact Form 7');

use CreativeMail\Modules\Contacts\Models\ContactFormSevenSubmission;
use CreativeMail\Modules\Contacts\Models\ContactModel;
use CreativeMail\Modules\Contacts\Models\OptActionBy;

class ContactFormSevenPluginHandler extends BaseContactFormPluginHandler
{

    private $emailFields = array('your-email', 'email', 'emailaddress', 'email_address');
    private $firstnameFields = array('firstname', 'first_name', 'name', 'your-name');
    private $lastnameFields = array('lastname', 'last_name');

    private function findValue($data, $fieldOptions)
    {
        foreach ($fieldOptions as $fieldOption) {
            $value = $data->get_posted_data($fieldOption);
            if (isset($value) && !empty($value)) {
                return $value;
            }
        }

        return null;
    }

    private function findValueFromDb($formData, $fieldOptions)
    {
        foreach ($fieldOptions as $fieldOption) {
            if (array_key_exists($fieldOption, $formData)) {
                $value = $formData[$fieldOption];
                if (!empty($value)) {
                    return $value;
                }
            }
        }
        return null;
    }

    public function convertToContactModel($contactForm)
    {
        $contactForm = ContactFormSevenSubmission::get_instance(null, array('skip_mail' => true));

        // convert
        $contactModel = new ContactModel();

        $email = $this->findValue($contactForm, $this->emailFields);
        if (!empty($email)) {
            $contactModel->setEmail($email);
        }

        $firstName = $this->findValue($contactForm, $this->firstnameFields);
        if (!empty($firstName)) {
            $contactModel->setFirstName($firstName);
        }

        $lastName = $this->findValue($contactForm, $this->lastnameFields);
        if (!empty($lastName)) {
            $contactModel->setLastName($lastName);
        }

        $contactModel->setEventType(CE4WP_CF7_EVENTTYPE);

        return $contactModel;
    }

    public function registerHooks()
    {
        add_action('wpcf7_mail_sent', array($this, 'ceHandleContactFormSevenSubmit'));
        // add hook function to synchronize
        add_action(CE4WP_SYNCHRONIZE_ACTION, array($this, 'syncAction'));
    }

    public function unregisterHooks()
    {
        remove_action('wpcf7_mail_sent', array($this, 'ceHandleContactFormSevenSubmit'));
        // add hook function to synchronize
        remove_action(CE4WP_SYNCHRONIZE_ACTION, array($this, 'syncAction'));
    }

    public function syncAction($limit = null)
    {
        if (!is_int($limit) || $limit <= 0) {
            $limit = null;
        }

        // Relies on plugin => Contact Form CFDB7
        if (in_array('contact-form-cfdb7/contact-form-cfdb-7.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            global $wpdb;

            $cfdb = apply_filters('cfdb7_database', $wpdb);
            $cfdbtable = $cfdb->prefix . 'db7_forms';
            $cfdbQuery = "SELECT form_id, form_post_id, form_value FROM $cfdbtable";

            // Do we need to limit the number of results
            if ($limit != null) {
                $cfdbQuery .= " LIMIT %d";
                $cfdbQuery = $cfdb->prepare($cfdbQuery, $limit);
            } else {
                $cfdbQuery = $cfdb->prepare($cfdbQuery);
            }

            $results = $cfdb->get_results($cfdbQuery, OBJECT);

            $contactsArray = array();

            foreach ($results as $formSubmission) {
                $form_data = unserialize($formSubmission->form_value);
                $contactModel = new ContactModel();
                $contactModel->setOptIn(true);
                $contactModel->setOptOut(false);
                $contactModel->setOptActionBy(OptActionBy::Visitor);
                try {
                    $email = $this->findValueFromDb($form_data, $this->emailFields);
                    if (!empty($email)) {
                        $contactModel->setEmail($email);
                    }
                    $firstname = $this->findValueFromDb($form_data, $this->firstnameFields);
                    if (!empty($firstname)) {
                        $contactModel->setFirstName($firstname);
                    }
                    $lastname = $this->findValueFromDb($form_data, $this->lastnameFields);
                    if (!empty($lastname)) {
                        $contactModel->setLastName($lastname);
                    }
                } catch (\Exception $exception) {
                    // silent exception
                    continue;
                }

                if (!empty($contactModel->getEmail())) {
                    $contactModel->setEventType(CE4WP_CF7_EVENTTYPE);
                    array_push($contactsArray, $contactModel);
                }
            }

            if (!empty($contactsArray)) {

                $batches = array_chunk($contactsArray, CE4WP_BATCH_SIZE);
                foreach ($batches as $batch) {
                    $this->batchUpsertContacts($batch);
                }
            }
        }
    }

    function ceHandleContactFormSevenSubmit($contact_form)
    {
        try {
            $this->upsertContact($this->convertToContactModel($contact_form));
        } catch (\Exception $exception) {
            // silent exception
        }
    }

    function __construct()
    {
        parent::__construct();
    }
}
