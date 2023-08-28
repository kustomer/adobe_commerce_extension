define([
    "jquery",
    'uiGridColumnsActions'
], function($, actions){
    'use strict';

    //make it draggable
    if($('body').hasClass('kustomer_webhookintegration-index-index')) {
        return actions.extend({
            defaults: {
                draggable: true
            }
        });
    }else{
        return actions;
    }
});