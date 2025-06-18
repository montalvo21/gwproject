<?php
defined('ABSPATH') || die();
/** @var $this NextendSocialProviderAdmin */

$lastUpdated = '2025-04-25';

$provider = $this->getProvider();
?>

<div class="nsl-admin-sub-content">
    <div class="nsl-admin-getting-started">
        <h2 class="title"><?php _e('Getting Started', 'nextend-facebook-connect'); ?></h2>

        <p><?php printf(__('To allow your visitors to log in with their %1$s account, first you must create a %1$s App. The following guide will help you through the %1$s App creation process. After you have created your %1$s App, head over to "Settings" and configure the given "%2$s" and "%3$s" according to your %1$s App.', 'nextend-facebook-connect'), "Google", "Client ID", "Client secret"); ?></p>

        <p><?php do_action('nsl_getting_started_warnings', $provider, $lastUpdated); ?></p>

        <h2 class="title"><?php printf(_x('Create %s', 'App creation', 'nextend-facebook-connect'), 'Google App'); ?></h2>

        <ol>
            <li><?php printf(__('Navigate to %s', 'nextend-facebook-connect'), '<a href="https://console.developers.google.com/apis/" rel="noopener noreferrer" target="_blank">https://console.developers.google.com/apis/</a>') ?></li>
            <li><?php printf(__('Log in with your %s credentials if you are not logged in.', 'nextend-facebook-connect'), 'Google'); ?></li>
            <li><?php printf(__('If you don\'t have a project yet, you\'ll need to create one. You can do this by clicking on the "%1$s" button (if you already have a project, then in the top bar click on the name of your project instead). This will bring up a modal, then click "%2$s".', 'nextend-facebook-connect'), '<b>Select a project</b>', '<b>NEW PROJECT</b>') ?></li>
            <li><?php printf(__('Name your project and then click on the "%s" button again!', 'nextend-facebook-connect'), '<b>Create</b>') ?></li>
            <li><?php printf(__('Once you have a project, you may see a window where you can click "%1$s". If this is not the case, click on "%2$s" button again in the top bar, and select the newly made project. (If earlier you have already had a Project, then make sure you select the created project in the top bar!)', 'nextend-facebook-connect'), '<b>SELECT PROJECT</b>', '<b>Select a project</b>') ?></li>
            <li><?php printf(__('Next, open the navigation menu (click on the burger icon, or press "." on your keyboard) and go to "%1$s" -> "%2$s".', 'nextend-facebook-connect'), '<b>APIs & Services</b>', '<b>OAuth consent screen</b>') ?></li>
            <li><?php printf(__('Click on the blue "%s" button.', 'nextend-facebook-connect'), '<b>Get started</b>') ?></li>
            <li><?php printf(__('Enter a name for your App to the "%s" field, which will appear as the name of the app asking for consent.', 'nextend-facebook-connect'), '<b>App name</b>') ?></li>
            <li><?php printf(__('For the "%s" field, select an email address that users can use to contact you with questions about their consent.', 'nextend-facebook-connect'), '<b>User support email</b>') ?></li>
            <li><?php printf(__('Click on "%1$s", and for the "%2$s" choose a "%3$s" according to your needs and press "%1$s" again. If you want to enable the social login with %4$s for any users with a %4$s account, then pick the "%5$s" option!', 'nextend-facebook-connect'), '<b>Next</b>', '<b>Audience</b>', '<b>User Type</b>', 'Google', '<b>External</b>') ?>
                <ul>
                    <li><?php printf(__('<b>Note:</b> We don\'t use sensitive or restricted scopes either. But if you will use this App for other purposes too, then you may need to go through an %1$s!', 'nextend-facebook-connect'), '<a href="https://support.google.com/cloud/answer/9110914" target="_blank">Independent security review</a>'); ?></li>
                </ul>
            </li>
            <li><?php printf(__('For the "%1$s" enter an email address (you can set more than one) %2$s can use to notify you about changes to the project, and click "%3$s" once more.', 'nextend-facebook-connect'), '<b>Contact Information</b>', 'Google', '<b>Next</b>') ?></li>
            <li><?php printf(__('Agree to the "%1$s", and click "%2$s".', 'nextend-facebook-connect'), '<b>Google API Services: User Data Policy</b>', '<b>Continue</b>') ?></li>
            <li><?php printf(__('You can review the information entered, and if all looks correct, click on the blue "%s" button.', 'nextend-facebook-connect'), '<b>Create</b>') ?></li>
            <li><?php printf(__('Next, select the "%s" option from the left menu.', 'nextend-facebook-connect'), '<b>Branding</b>') ?></li>
            <li><?php printf(__('You can update the "%1$s" and "%2$s" here if needed.', 'nextend-facebook-connect'), '<b>App name</b>', '<b>User support email</b>') ?></li>
            <li><?php printf(__('Select an "%s".', 'nextend-facebook-connect'), '<b>App logo</b>') ?>
                <ul>
                    <li><?php printf(__('<b>Note:</b> After you upload a logo, you will need to submit your app for verification unless the app is configured for internal use only or has a publishing status of "%1$s"!', 'nextend-facebook-connect'), 'Testing'); ?></li>
                </ul>
            </li>
            <li><?php printf(__('Next, provide the appropriate links to the "%1$s", "%2$s" and "%3$s" fields.', 'nextend-facebook-connect'), '<b>Application home page</b>', '<b>Application privacy policy link</b>', '<b>Application terms of service link</b>') ?></li>
            <li><?php printf(__('Under the "%1$s" section press the "%2$s" button and enter your domain name without subdomains, probably: %3$s', 'nextend-facebook-connect'), '<b>Authorized domains</b>', '<b>Add Domain</b>', ('<b>' . str_replace('www.', '', $_SERVER['HTTP_HOST'])) . '</b>'); ?></li>
            <li><?php printf(__('Once you are done, click on the "%s" button.', 'nextend-facebook-connect'), '<b>Save</b>') ?></li>
            <li><?php printf(__('Next, select the "%1$s" option from the left menu, then click "%2$s".', 'nextend-facebook-connect'), '<b>Clients</b>', '<b>Create client</b>') ?></li>
            <li><?php printf(__('Choose "%1$s" as the "%2$s", and enter a name.', 'nextend-facebook-connect'), '<b>Web application</b>', '<b>Application type</b>') ?></li>
            <li><?php
                $loginUrls = $provider->getAllRedirectUrisForAppCreation();
                printf(__('Under the "%1$s" section click "%2$s" and add the following URL:', 'nextend-facebook-connect'), '<b>Authorised redirect URIs</b>', '<b>Add URI</b>');
                echo "<ul>";
                foreach ($loginUrls as $loginUrl) {
                    echo "<li><b>" . $loginUrl . "</b></li>";
                }
                echo "</ul>";
                ?>
            </li>
            <li><?php printf(__('Click on the "%s" button.', 'nextend-facebook-connect'), '<b>Create</b>') ?></li>
            <li><?php printf(__('A modal should pop up with your credentials. If that doesn\'t happen, go to the "%1$s" in the left hand menu and select your app by clicking on its name and you\'ll be able to copy-paste the "%2$s" and "%3$s" from there.', 'nextend-facebook-connect'), '<b>Clients</b>', '<b>Client ID</b>', '<b>Client Secret</b>') ?></li>
            <li><?php printf(__('Currently, your App is in "%1$s" mode, so only limited number of people can use it. To allow this App for any user with a Google Account, click on the "%2$s" option on the left side, then click the "%3$s" button under the "%4$s" section, and press the "%5$s" button.', 'nextend-facebook-connect'), '<b>Testing</b>', '<b>Audience</b>', '<b>Publish app</b>', '<b>Publishing status</b>', '<b>Confirm</b>') ?></li>
        </ol>


        <a href="<?php echo $this->getUrl('settings'); ?>"
           class="button button-primary"><?php printf(__('I am done setting up my %s', 'nextend-facebook-connect'), 'Google App'); ?></a>
    </div>
</div>