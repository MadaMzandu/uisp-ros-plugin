# UISP/UCRM Plugin for RouterOs Services

So it finally came to be, a simple to install plugin replacement for the previous api based version.

# New Features

1. All new graphical interface for adding devices and managing settings
2. One click installation via uisp plugin interface
3. Contention ratios - the plugin will now create and manage parent queues to apply contention per router device.
4. Automatic ppp profile creation - ppp profiles for service plans are now created/removed automatically
5. Automatic ppp username and password generation
6. Rebuild utility - the plugin can now re-sync itself and router devices against uisp. A new router can now be repopulated with accounts in minutes.
7. Automatic backup - although the plugin can resync itself with uisp and managed routers, there is a now backup feature for added redundancy.

## Other features
1. Real time provisioning, editing, suspending, unsuspending of accounts
2. Real time migration of accounts between devices
3. Supports both dhcp and pppoe and other ppp variants

# Kofi

If this work adds value to your project then take the time and send the programmer some Kofi. 
https://ko-fi.com/madamzandu

# Installation

1. Download the binary zip file "ros-plugin.zip" and upload into your Uisp > Settings > Plugins.

2. Enable the plugin and create the webhook.

# Configuration

1. After enabling the plugin a menu icon will be installed for the plugin. Click on the icon and go to panel.
2. In the devices tab add your mikrotik devices
3. In the plans tab set the contention ratios for your plans or leave as 1:1 if not selling contention. If no plans are defined yet, define your plans in Uisp > Settings > Service Plans & Products
4. In the settings tab go to the attributes tab and define the attributes that you want to use. You will need device name and mac address for dhcp, pppoe username,password and device name for pppoe. You can also set an ip address attribute if you wish to manually assign ip addresses for some accounts. You can also enable all the attributes if you are using both pppoe and dhcp.
5. If using pppoe, in the Settings > General tab select if you want to use one pool for all your routers and specify the pool, or you want to use per router pool specified in the device.
6. Thats it your are done.

# Upgrading
If you have been using the previous api based version:

1. After installation and configuration above disable the webhook for the previous api based version.
2. Go to the settings tab > system and click rebuild to populate with accounts from previous api version.

Please ensure that your mikrotik devices are added to the plugin and are online. The rebuild process is harmless and can be run any number of times. To verify if rebuild was successful go to the Devices tab and check the number of user accounts listed for each device.

# Using

The custom attributes in Configution (4) should be listed in the form when creating or editing a service in Uisp.
1. Fill the device name to specify the router for the clients account (see making a device dropdown list below)
2. PPPoE username and Password to provision PPPoE.
3. Mac address to provision DHCP instead.
4. IP address to bypass the pool

## IPv6 Over PPP

Version 1.8.5 introduces support for ipv6 gateway devices and ipv6 over ppp (without PD). Ipv6 for dhcp is
not implemented in this version.

Ipv6 over ppp routes an ipv6 prefix to a ppp client's device which can then be used for dhcp or other 
services on the client's lan.

In the device settings provide an ipv6 prefix of reasonable size e.g. a /48. This prefix can be reused
on more than one router and the plugin will manage and ensure that client allocations have no conflicts.
After specifying the prefix you can specify the size or prefix length to assign clients in the prefix 
length field. If the prefix length field is empty the plugin will default to assigning /64's to clients.

When a pppoe client is added the plugin will allocate an ipv6 /64 along with the normal ipv4 framed address.
The ipv6 assignments can be viewed in the panel > devices > click on one of the devices.


## Hotspot Account Support

## Localized Languages

Version 1.8.5 adds support for Swahili and Afrikaans. Please let me have feedback if there are problems.

In version 1.8.4 we have added and are testing support for languages. Currently the languages supported are en,es,fr,pt and de.

Please open an issue if you think there is a problem with the translations. For those that can spare the time and would like to contribute by translating to your preferred language the instructions will soon be posted in https://github.com/MadaMzandu/uisp-ros-plugin/tree/main/src/includes/l10n.

Once again thank you all for your invaluable feedback.

## Job queue - new feature

Version 1.8.3 now has a job queue to handle webhook requests that arrive when the target device is unreachable. 

When the plugin receives a webhook for a mikrotik device that is offline the request is automatically queued for later execution. The job queue can be executed automatically by enabling the plugin's scheduled execution or manually from Panel > Settings > Jobs then clicking on the "run queue" button.

## Contention ratios and volume
The maximum queue size that is acceptable on a Mikrotik is 4.294Gbps or 4294Mbps. If the total rate of the parent queue exceeds this limit the plugin will fail to add new customers to the plan until the contention ratio is adjusted. 

If contention is enabled in the plugin then admin must ensure that plan limit x number of plan customers x contention ratio does not exceed 4.294Gb. 

For example a plan for 50Mbps targeting 200 customers with a contention ratio of 1:1 (1/1) will not work because 50 x 200 x 1/1 = 10000Mbps or 10Gbs which exceeds the Mikrotik limit. The correct contention ratio for this plan must start at 1:3 (1/3) or above which is 50 x 200 x 1/3 = 3.3Gbps. This will work on Mikrotik.

If contention is not required then disable contention in Panel > Settings > General. Warning!! Disabling contention requires all customers to disconnect to apply changes therefore schedule maintanance especially if pppoe clients are on ubiquiti devices. PPPoE clients on mikrotik devices will reconnect in less than a second so this is not a problem for Mikrotik pppoe clients.

## Enabling automatic username and password generation

1. You need to go to panel > settings > general and enable the checkbox for this feature.
2. When adding a service for a client leave the username and password blank to let plugin generate the field automatically, thats it.

Also note the following;

1. A manually typed in username or password will override automatic generation for the field.
2. Deleting the existing username or password will cause the plugin to generate a new value for the field
3. The username is either client login, client lastname or client company name with service number appended.

## Making a dropdown device list

1. Go to crm settings > other > custom attributes.
2. Create a new custom attribute with the following parameters - type: choice, Attribute type: service,Client Visible:no.
3. Add the device names of your managed mikrotiks as values. You have to manually update this list of values when you add a new device.
4. Next go to plugin panel > settings > attributes and in the device name field type in the name of the attribute created in step 2. 
5. Click the save button when the panel finds the attribute.

There are two reasons why this can only be done manually if you are interested to know: 

1. Plugins can query crm but crm cannot query plugins. And since custom attrbutes are part of crm it means they cannot pull values such as device names from the plugin.
2. Why not push the list of devices to crm then? True this would be semi static fallback solution but crm API has not provided a call that can push values to an enumerated custom attribute so for now this will have to do.

## Handling account suspension and unsuspension

The plugin provides four mikrotik parameters that allow customizing how disabled accounts are handled. These are:
1. required : disabled profile - the name of a ppp profile to assign suspended accounts. Created automatically if missing.
2. required : disabled address list - the name of firewall address list to assign suspended accounts. Firewall rules for the the list must be manually configured by admin. The rules can be nat rules to redirect and filter rules to drop.
3. required : disabled rate - the rate limit to apply to disabled accounts. Automatically applied to ppp profile or dhcp queue. 
4. optional : active address list - extra address list to apply to active accounts. Firewall rules must be manually configured by admin.

## Recovering from a failed rebuild

Should the rebuild utility fail to complete, simply restore the latest backup that is listed and the plugin should be restored to its previous state. Note that the rebuild utility makes a backup each time it is executed and that the plugin rotates every 7th backup. Therefore it is advisable to download at least one backup when things get out of hand.

## Actions that interrupt customer service.

The following actions require an active pppoe to disconnect to apply changes. This is normal behaviour. For mikrotik pppoe clients this takes less than a second however ubiquiti pppoe clients seem to take longer to reconnect after an administrative disconnect so it is advisable to use certain features with caution.

1. Any edit of customer's username,password,ip address or callerid causes the active pppoe for the customer to be disconnected to apply changes.
2. Changing the customer's plan, suspending the customer or moving the customer to a different device will also disconnect the active account to apply changes.
3. Enabling or Disabling parent queues using "Panel > Settings > Disable Contention" requires ALL pppoe services to disconnect to apply changes.

# Credits

This software uses or depends on the following software by these developers with
the greatest gratitude.

Ben Menking – RouterOS API

<https://github.com/BenMenking/routeros-api>

Ubiquiti - UISP/UCRM/UNMS

<https://ubnt.com>

Mikrotik - RouterOS

<https://mikrotik.com>




