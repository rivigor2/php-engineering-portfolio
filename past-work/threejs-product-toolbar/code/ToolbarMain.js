var _root_script_url = "https://portfolio.example/toolbar/";
var _url_to_static   = "https://cdn.example/catalogues/";
var _api_url         = "https://api.example/";

var _lastJsonResponseData;
var _lastRequestData;
var _memberUniq;
var _customizeBreadcrumbs = new Map([['HOME', 'home:0']]);
var jQuery;
var _delayAjaxObj = _delayAjax(1000);
var _catalogueActual;
var _companyActual;
var _catalogueMode;
var _catalogueUid;

(function() {
    // +++подключение jquery если его нет.+++
    if (window.jQuery === undefined) {
        var _script_tag_ = document.createElement('script');
        _script_tag_.setAttribute("type","text/javascript");
        _script_tag_.setAttribute("src", _root_script_url + 'src/lib/jquery.js');
        _script_tag_.onload = _scriptLoadHandler;
        _script_tag_.onreadystatechange = function () { // IE
            if (this.readyState === 'complete' || this.readyState === 'loaded') {
                _scriptLoadHandler();
            }
        };
        (document.getElementsByTagName("head")[0] || document.documentElement).appendChild(_script_tag_);

    } else {
        $ = jQuery = window.jQuery;
        _controlButtonsInit();
    }
    function _scriptLoadHandler() {
        $ = jQuery = window.jQuery.noConflict(true);
        _controlButtonsInit();
    }
    // ---подключение jquery если его нет.---

    function _controlButtonsInit() {
        $(document).ready(function() {
			_memberUniq    = _getUrlParams('member_uniq');
			_catalogueMode = _getUrlParams('catalogue_mode');
			_catalogueUid  = _catalogueMode;
            _buildControlPanelConstructor();
			_initMemberToolbar(_memberUniq, _catalogueMode);
        });
    }
})();

function _initMemberToolbar(_memberUniq, _catalogueMode) {
	$.ajax({
		url: _api_url + 'player_config/?member_uniq=' + _memberUniq + '&catalogue_mode=' + _catalogueMode,
		async: false,
		dataType: 'json',
		success: function (response) {
			if(response.error === false) {
				_buildControlPanelMember(response.data_array);
			} else {
				var error = new Map([
					['method', 'player_config'],
					['_memberUniq', _memberUniq],
					['msg', response.msg]
				]);
				console.log(error);
				alert('Error found - check console;');
				_buildControlPanelMember([]);
			}
		},
		error: function () {
			console.log('Error ajax _initMemberToolbar;');
			alert('Error found - check console;');
			_buildControlPanelMember([]);
		}
	});
}

function _buildControlPanelConstructor() {
	_setMainFrameCss();
	_setAjaxProps();
	_addExtraLibs();
	_addControlBlock('main_control_parrent_block');
	_addControlBlock('main_control_parrent_block_relative');
	_addControlBlock('loading_main');
	_addControlBlock('popup_main');
	_addLisener('popup_main');
	_addLisener('full_screen_exit');
	_addControlBlock('main_control_child_block_left');
	_addControlBlock('main_control_child_block_right');
	_startLoading();
}

function _addLisener(_lisenerName) {
	switch(_lisenerName) {

		case 'popup_main':
		 $('#popup_close').click(function(){
			$('#popup_main').hide();
			_clearContentToPopup();
		 });
		break;

		case 'button_help':
			$('#button_help').click(function(){
				_getContentToPopup('button_help');
				$('#popup_main').show();
			});
		break;

		case 'button_calc':
			$('#button_calc').click(function(){
				_getContentToPopup('button_calc');
				$('#popup_main').show();
			});
		break;

		case 'screen_shot':
			$('#screen_shot').click(function(){
				_doScreenShot();
			});
		break;

		case 'full_screen':
			$('#full_screen').click(function(){
				_doFullScreen();
			});
			$('#small_screen').click(function(){
				_doSmallScreen();
			});
		break;

		case 'button_customize':
			$('#button_customize').click(function(){
				_toogleCustomizeMain();
			});
		break;

		case 'button_catalogue':
			$('#button_catalogue').click(function(){
				_toogleCatalogueMain();
			});
		break;

		case 'breadcrumb_el':
			$('.breadcrumb_el').click(function(){
				_getCustomizeByBreadcrumb($(this));
			});
		break;

		case 'customize_company_div':
			$('.customize_company_div').click(function(){
				_getCatalogueByCompanyUid($(this));
			});
		break;

		case 'customize_catalogue_div':
			$('.customize_catalogue_div').click(function(){
				_getCataloguesHierarchy($(this));
			});
		break;

		case 'customize_el_div':
			$('.customize_el_div').click(function(){
				_customizeElementRun($(this));
			});
		break;

		case 'customize_el_close':
			$('.customize_el_close').click(function(){
				$('#customize_el').hide();
				$('.tooltip').hide();
				$('.tooltip').remove();
			});
		break;

		case 'full_screen_exit':
			_fullScreenExitLisener();
		break;

		case 'cancel_search':
			$('#cancel_search').click(function(){
				_cancelSearch();
			});
		break;

		case 'input_search':
			$('#customize_breadcrumbs_search_input').bind('keyup change', function() {
				_delayAjaxObj(_customizeBreadcrumbsSearchRun, this);
			});
		break;


	}
}


function _addControlBlock(_blockName, _blockDirection) {

	if (_blockDirection != 'main_control_child_block_left' && _blockDirection != 'main_control_child_block_right') {
		_blockDirection = 'main_control_child_block_left';
	}

	switch(_blockName) {

		// STATIC
		// main block
		case 'main_control_parrent_block':
			$('body').after(_getTemplateHtml(_blockName));
		break;

		case 'main_control_parrent_block_relative':
			$('#main_control_parrent_block').prepend(_getTemplateHtml(_blockName));
		break;

		// child blocks
		case 'main_control_child_block_left':
			$('#main_control_parrent_block_relative').append(_getTemplateHtml(_blockName));
		break;

		case 'main_control_child_block_right':
			$('#main_control_parrent_block_relative').append(_getTemplateHtml(_blockName));
		break;

		//popup
		case 'popup_main':
			$('#main_control_parrent_block_relative').append(_getTemplateHtml(_blockName));
		break;

		//preloading
		case 'loading_main':
			$('#main_control_parrent_block_relative').append(_getTemplateHtml(_blockName));
		break;

		// DINAMIC
		// buttons
		case 'button_help':
			$('#' + _blockDirection).append(_getTemplateHtml(_blockName));
		break;

		case 'screen_shot':
			$('#' + _blockDirection).append(_getTemplateHtml(_blockName));
		break;

		case 'full_screen':
			$('#' + _blockDirection).append(_getTemplateHtml(_blockName));
			$('#' + _blockDirection).append(_getTemplateHtml('small_screen'));
			$('#small_screen').hide();
		break;

		case 'button_calc':
			$('#' + _blockDirection).append(_getTemplateHtml(_blockName));
		break;

		// CUSTOMIZE
		case 'button_customize':
			$('#' + _blockDirection).append(_getTemplateHtml(_blockName));
			$('#main_control_parrent_block_relative').append(_getTemplateHtml('customize_main'));
			$('#main_control_parrent_block_relative').append(_getTemplateHtml('customize_el'));
			$('#customize_main').hide();
			$('#customize_el').hide();
			if (_blockDirection == 'main_control_child_block_left') {
				$('#customize_main').css('right', '');
				$('#customize_main').css('left', 0);
				$('#customize_el').css('right', '');
				$('#customize_el').css('left', '250px');
			}
			_buildCustomize();
		break;

		case 'customize_breadcrumbs':
			$('#customize_main').append(_getTemplateHtml(_blockName));
			$('#customize_breadcrumbs').append(_getTemplateHtml('cancel_search'));
			$('#customize_breadcrumbs').append(_getTemplateHtml('search_icon'));
			_addLisener('input_search');
			_addLisener('cancel_search');
		break;

		case 'customize_content':
			$('#customize_main').append(_getTemplateHtml(_blockName));
		break;

		case 'catalogue_content':
			$('#catalogue_main').append(_getTemplateHtml(_blockName));
		break;

		case 'catalogue_header':
			$('#catalogue_main').append(_getTemplateHtml(_blockName));
			$('#catalogue_header').append(_getTemplateHtml('catalogue_search'));
			$('#catalogue_header').append(_getTemplateHtml('catalogue_icon'));
			_addLisener('catalogue_search');
			_addLisener('catalogue_search');
		break;

		case 'customize_el_content':
			$('#customize_el').append(_getTemplateHtml(_blockName));
		break;

		// CATALOGUE
		case 'button_catalogue':
			$('#' + _blockDirection).append(_getTemplateHtml(_blockName));
			$('#main_control_parrent_block_relative').append(_getTemplateHtml('catalogue_main'));
			$('#catalogue_main').hide();
			if (_blockDirection == 'main_control_child_block_left') {
				$('#catalogue_main').css('right', '');
				$('#catalogue_main').css('left', 0);
			}
			_buildCatalogue();
		break;
	}
}

function _getTemplateHtml(_templateName) {

	var _templateHtml = "";

	switch(_templateName) {

		case 'main_control_parrent_block':
			_templateHtml = "<div id = 'main_control_parrent_block' class = 'main_control_parrent_block'></div>";
		break;

		case 'main_control_parrent_block_relative':
			_templateHtml = "<div id = 'main_control_parrent_block_relative' class = 'main_control_parrent_block_relative'></div>";
		break;

		case 'main_control_child_block_left':
			_templateHtml = "<div id = 'main_control_child_block_left' class = 'main_control_child_block_left'></div>";
		break;

		case 'main_control_child_block_right':
			_templateHtml = "<div id = 'main_control_child_block_right' class = 'main_control_child_block_right'></div>";
		break;

		case 'popup_main':
			_templateHtml = "<div id = 'popup_main' class = 'popup_main'><div id = 'popup_relative' class = 'popup_relative'><div id = 'popup_close' class = 'popup_close'><img src = '" + _root_script_url + "src/imgs/button_close.svg' /></div><div id = 'popup_content' class = 'popup_content'></div></div></div>";
		break;

		case 'loading_main':
			_templateHtml = "<div id = 'loading_main' class = 'loading_main'><div id = 'loading_main_progress' class = 'loading_main_progress' ></div></div>";
		break;

		case 'button_help':
			_templateHtml = "<div id = 'button_help' class = 'button_div'><img class = 'button_svg' src = '" + _root_script_url + "src/imgs/button_help.svg'></div>";
		break;

		case 'screen_shot':
			_templateHtml = "<div id = 'screen_shot' class = 'button_div'><img class = 'button_svg' src = '" + _root_script_url + "src/imgs/screen_shot.svg'></div>";
		break;

		case 'full_screen':
			_templateHtml = "<div id = 'full_screen' class = 'button_div'><img class = 'button_svg' src = '" + _root_script_url + "src/imgs/full_screen.svg'></div>";
		break;

		case 'small_screen':
			_templateHtml = "<div id = 'small_screen' class = 'button_div'><img class = 'button_svg' src = '" + _root_script_url + "src/imgs/small_screen.svg'></div>";
		break;

		case 'button_calc':
			_templateHtml = "<div id = 'button_calc' class = 'button_div'><img class = 'button_svg' src = '" + _root_script_url + "src/imgs/button_calc.svg'></div>";
		break;

		case 'button_customize':
			_templateHtml = "<div id = 'button_customize' class = 'button_div'><img class = 'button_svg' src = '" + _root_script_url + "src/imgs/button_customize.svg'></div>";
		break;

		case 'button_catalogue':
			_templateHtml = "<div id = 'button_catalogue' class = 'button_div'><img class = 'button_svg' src = '" + _root_script_url + "src/imgs/button_customize.svg'></div>";
		break;

		case 'cancel_search':
			_templateHtml = "<div id = 'cancel_search' class = 'cancel_search'><img class = 'button_svg' src = '" + _root_script_url + "src/imgs/cancel_search.svg'></div>";
		break;

		case 'search_icon':
			_templateHtml = "<div id = 'search_icon' class = 'search_icon'><img src = '" + _root_script_url + "src/imgs/search_icon.svg'></div>";
		break;

		case 'no_result':
			_templateHtml = "<div id = 'no_result' class = 'no_result'><img src = '" + _root_script_url + "src/imgs/no_result.svg'><br> Нет результатов. </div>";
		break;

		case 'customize_main':
			_templateHtml = "<div id = 'customize_main' class = 'customize_main'></div>";
		break;

		case 'catalogue_main':
			_templateHtml = "<div id = 'catalogue_main' class = 'catalogue_main'></div>";
		break;

		case 'customize_breadcrumbs':
			_templateHtml = "<div id = 'customize_breadcrumbs' class = 'customize_breadcrumbs'><div class = 'customize_breadcrumbs_search_div' id = 'customize_breadcrumbs_search_div' ><input disabled type = 'text' id = 'customize_breadcrumbs_search_input' class = 'customize_breadcrumbs_search_input'/></div><div  class = 'customize_breadcrumbs_content' id = 'customize_breadcrumbs_content'></div></div>";
		break;

		case 'customize_content':
			_templateHtml = "<div id = 'customize_content' class = 'customize_content'></div>";
		break;

		case 'catalogue_content':
			_templateHtml = "<div id = 'catalogue_content' class = 'catalogue_content'></div>";
		break;

		case 'catalogue_header':
			_templateHtml = "<div id = 'catalogue_header' class = 'catalogue_header'> <div class = 'catalogue_header_content' id = 'catalogue_header_content'></div> <div class = 'catalogue_header_search_div' id = 'catalogue_header_search_div' ><input type = 'text' id = 'catalogue_header_search_input' class = 'catalogue_header_search_input'/></div></div>";
		break;

		case 'customize_el':
			_templateHtml = "<div id = 'customize_el' class = 'customize_el'></div>";
		break;

		case 'customize_el_content':
			_templateHtml = "<div id = 'customize_el_content' class = 'customize_el_content'></div>";
		break;

	}

	return _templateHtml;
}

function _buildControlPanelMember(_memberSettings) {
	for (var _keySetting in _memberSettings){
		if (_memberSettings.hasOwnProperty(_keySetting)) {
			_addControlBlock(_keySetting, _memberSettings[_keySetting]);
			_addLisener(_keySetting);
		}
	}
	_stopLoading();
}

function _toogleCustomizeMain() {
	$('#customize_main').slideToggle(100);
	$('#customize_el').hide();
}

function _toogleCatalogueMain() {
	$('#catalogue_main').slideToggle(100);
}

function _buildCustomize() {
	var _iframe = parent.document.getElementById('vrnextMainFrame');
	var _iframeHeight = $(_iframe).height() - 75;
	var _iframeWidth  = $(_iframe).width();
	_resizeCustomize(_iframeHeight);
	_addControlBlock('customize_breadcrumbs');
	_buildCustomizeBreadcrumbs();
	_addControlBlock('customize_content');
	_addControlBlock('customize_el_content');
	_doGetRequestToJson('companies_list_with_catalogues', '', '_companiesListWithCataloguesResult');
}

function _buildCatalogue() {
	var _iframe = parent.document.getElementById('vrnextMainFrame');
	var _iframeHeight = $(_iframe).height() - 75;
	var _iframeWidth  = $(_iframe).width();
	_resizeCatalogue(_iframeHeight);
	_addControlBlock('catalogue_header');
	_buildCatalogueHeader();
	_addControlBlock('catalogue_content');
	_doGetRequestToJson('catalogues_products', 'catalogue_uid[]=' + _catalogueUid, '_cataloguesProductsModeResult');
}

function _buildCustomizeBreadcrumbs(_value, _key, _action) {
	if (_action == 'set') {
		_customizeBreadcrumbs.set(_key, _value);
	}
	if (_action == 'delete') {
		var _forDelete = false;
		_customizeBreadcrumbs.forEach((value, key, map) => {
			if (_value == value) {
				_forDelete = true;
			}
			if (_forDelete) {
				if (value != _value) {
					_customizeBreadcrumbs.delete(key);
				}
			}
		});
	}
	_content = '';
	_customizeBreadcrumbs.forEach((value, key, map) => {
		_content += "<b class = 'breadcrumb_slash'>/</b><span class = 'breadcrumb_el' breadcrumb_uid = '"+ value +"'>"+ key +"</span>";
	});
	_addContentToCustomize(_content, 'customize_breadcrumbs_content');
	_addLisener('breadcrumb_el');
}

function _buildCatalogueHeader(){
	_content = 'test';
	_addContentTo(_content, 'catalogue_header_content');
}

function _getCustomizeByBreadcrumb(_obj) {
	var _breadcrumb_attr = _obj.attr('breadcrumb_uid');
	var _breadcrumb_arr = _breadcrumb_attr.split(':');

	var _need = _breadcrumb_arr[0];
	var _uid  = _breadcrumb_arr[1];

	switch(_need) {
		case 'home':
			_doGetRequestToJson('companies_list_with_catalogues', '', '_companiesListWithCataloguesResult');
		break;
		case 'company':
		   _doGetRequestToJson('catalogues_by_company_uid', 'uid=' + _uid, '_cataloguesByCompanyUidResult');
		break;
		case 'catalogue':
		   _doGetRequestToJson('catalogues_hierarchy', 'parent_uniq=' + _uid, '_cataloguesCataloguesHierarchyResult');
		break;
	}
	_buildCustomizeBreadcrumbs(_breadcrumb_attr, '', 'delete');
}

function _companiesListWithCataloguesResult(_data_array) {
	_disableSearch();
	var _content     = '';
	var _companyName = '';
	var _companyUid  = '';

	for (var _key in _data_array) {
		for (var _keyIn in _data_array[_key]) {
			if (_keyIn == 'name') {
				_companyName = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'uid') {
				_companyUid = _data_array[_key][_keyIn];
			}
		}
		_content += "<div class = 'customize_company_div' _companyUid = '" + _companyUid + "' >" + _companyName + "</div>";
	}
	_addContentToCustomize(_content, 'customize_content');
	_addLisener('customize_company_div');
}

function _getCatalogueByCompanyUid(_obj) {
	_companyUid    = _obj.attr('_companyUid');
	_companyActual = _companyUid;
	_companyName   = _obj.html();
	_buildCustomizeBreadcrumbs('company:' + _companyUid, _companyName, 'set');
	_doGetRequestToJson('catalogues_by_company_uid', 'company_uid=' + _companyUid, '_cataloguesByCompanyUidResult');
}

function _cataloguesByCompanyUidResult(_data_array) {
	var _catalogueUid  = '';
	var _query         = 'parent_uniq=null&';
	for (var _key in _data_array) {
		for (var _keyIn in _data_array[_key]) {
			if (_keyIn == 'uid') {
				_catalogueUid = _data_array[_key][_keyIn];
			}
		}
		_query += "catalogue_uid[]=" + _catalogueUid + "&";
	}
	_doGetRequestToJson('catalogues_hierarchy', _query, '_cataloguesCataloguesHierarchyResult');
}

function _cataloguesCataloguesHierarchyResult(_data_array) {
	if (_data_array.length == 0) {
		_startLoading();
		var _hierarchy_uniq = _getFirstValueFromQuery(_lastRequestData);
		var _query          = 'hierarchy_uniq[]=' + _hierarchy_uniq;
		_doGetRequestToJson('catalogues_products_groups_by_hierarchy', _query, '_cataloguesProductsGroupsByHierarchyResult');
	} else {
		var _content        = '';
		var _catalogueName  = '';
		var _hierarchyUniq  = '';
		var _catalogueUid   = '';

		for (var _key in _data_array) {
			for (var _keyIn in _data_array[_key]) {
				if (_keyIn == 'name') {
					_catalogueName = _data_array[_key][_keyIn];
				}
				if (_keyIn == 'uniq') {
					_hierarchyUniq = _data_array[_key][_keyIn];
				}
				if (_keyIn == 'catalogue_uid') {
					_catalogueUid = _data_array[_key][_keyIn];
				}
			}
			_content += "<div class = 'customize_catalogue_div' _catalogueUid = '" +  _catalogueUid + "' _hierarchyUniq = '" + _hierarchyUniq + "' >" + _catalogueName + "</div>";
		}
		_addContentToCustomize(_content, 'customize_content');
		_addLisener('customize_catalogue_div');
	}
}

function _cataloguesProductsGroupsByHierarchyResult(_data_array) {
	var _productUniq   = '';
	var _query         = 'company_uid=' + _companyActual + '&';
	for (var _key in _data_array) {
		for (var _keyIn in _data_array[_key]) {
			if (_keyIn == 'product_uniq') {
				_productUniq = _data_array[_key][_keyIn];
			}
		}
		_query += "product_uniq[]=" + _productUniq + "&";
	}
	_doGetRequestToJson('catalogues_materials_references_by_product_uniq', _query, '_productResult');
}

function _productResult(_data_array) {
	var _content       = "<div id = 'customize_el_close' class = 'customize_el_close'><img src = '" + _root_script_url + "src/imgs/button_close.svg'/></div>";

	var _previewImgSrc = '';
	var _productUniq   = '';
	var _manufactorer  = '';
	var _name 		   = '';
	var _dim_x 		   = '';
	var _dim_y  	   = '';
	var _dim_z 		   = '';
	var _flags 		   = '';
	var _checksum      = '';
	var _checksum_3    = '';
	var _catalogue_uid = '';
	var _article	   = '';
	var _available	   = '';
	var _price	       = '';
	var _units	       = '';
	var _company_uid   = '';

	for (var _key in _data_array) {
		for (var _keyIn in _data_array[_key]) {
			if (_keyIn == 'catalogue_uid') {
				_catalogue_uid = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'product_uniq') {
				_productUniq = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'manufactorer') {
				_manufactorer = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'name') {
				_name = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'dim_x') {
				_dim_x = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'dim_y') {
				_dim_y = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'dim_z') {
				_dim_z = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'flags') {
				_flags = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'checksum') {
				_checksum      = _data_array[_key][_keyIn];
				if (_checksum != null) {
					_checksum_3    = _checksum.substring(0,3);
				}
				_previewImgSrc = _url_to_static + _catalogue_uid + '/' + _checksum_3 + '/' + _checksum + ".jpg";
			}
			if (_keyIn == 'article') {
				_article = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'available') {
				_available = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'price') {
				_price = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'units') {
				_units = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'company_uid') {
				_company_uid = _data_array[_key][_keyIn];
			}
		}
		_content += "<div title='article: "+_article+"|available: "+_available+"|price: "+_price+"|units: "+_units+"|company_uid: "+_company_uid+"|uniq: "+_productUniq+"|manufactorer: "+_manufactorer+"|name: "+_name+"|dim_x: "+_dim_x+"|dim_y: "+_dim_y+"|dim_z: "+_dim_z+"|flags: "+_flags+"' class = 'customize_el_div' _productuniq = '" + _productUniq + "' ><img src = '"+ _previewImgSrc +"'/></div>";
		_previewImgSrc = _productUniq = _manufactorer = _name = _dim_x = _dim_y = _dim_z = _flags = _checksum = _checksum_3 =  _article	= _available =  _price = _units = _company_uid = '';

	}

	if (_data_array.length == 0) {
		_content += _getTemplateHtml('no_result');
	}

	if (_catalogue_uid != '') {
		_catalogueActual = _catalogue_uid;
	}

	_enableSearch();

	_addContentToCustomize(_content, 'customize_el_content');
	_toolTips('customize_el_div');
	$('#customize_el').show(100);
	_addLisener('customize_el_close');
	_addLisener('customize_el_div');
	_stopLoading();
}

function _customizeBreadcrumbsSearchRun() {
	var _search = $('#customize_breadcrumbs_search_input').val();
	var _query = 'search=' + _search + '&catalogue=' + _catalogueActual;
	if (_search.length > 1) {
		_doGetRequestToJson('product_search', _query, '_productResult');
	}
}

function _customizeElementRun(_obj) {
	_productuniq  = _obj.attr('_productuniq');

	//todo
	console.log(_productuniq);
}

function _cancelSearch() {
	$('#customize_breadcrumbs_search_input').val('');
}

function _getCataloguesHierarchy(_obj) {
	_hierarchyUniq   = _obj.attr('_hierarchyUniq');
	_catalogueName   = _obj.html();
	_catalogueActual = _obj.attr('_catalogueUid');
	_enableSearch();
	_buildCustomizeBreadcrumbs('catalogue:' + _hierarchyUniq, _catalogueName, 'set', );
	_doGetRequestToJson('catalogues_hierarchy', 'parent_uniq=' + _hierarchyUniq, '_cataloguesCataloguesHierarchyResult');
}

function _getFirstValueFromQuery(_query) {
	var _arr = _query.split('=');
	return _arr[1];
}

function _resizeCustomize(_height, _width) {
	if ($('#customize_main').length) {
		$('#customize_main').css('height', _height);
	}
	if ($('#customize_el').length) {
		$('#customize_el').css('height', _height - 40);
	}
	if ($('#customize_breadcrumbs_content').length) {
		$('#customize_breadcrumbs_content').css('width', _width);
	}
	if ($('#customize_breadcrumbs_search_div').length) {
		$('#customize_breadcrumbs_search_div').css('right', (_width * -1) + 260);
		$('#cancel_search').css('right', (_width * -1) + 262);
		$('#search_icon').css('right', (_width * -1) + 572);
	}
}

function _resizeCatalogue(_height, _width) {
	if ($('#catalogue_main').length) {
		$('#catalogue_main').css('height', _height);
	}
}

function _resizeCustomizeContent() {
	var _customizeMainHeight                  = $('#customize_main').height();
	var _mainControlParrentBlockRelativeWidth = $('#main_control_parrent_block_relative').width();

	_customizeMainHeight = _customizeMainHeight - 40;

	if ($('#customize_breadcrumbs_content').length) {
		$('#customize_breadcrumbs_content').css('width', _mainControlParrentBlockRelativeWidth);
	}

	if ($('#customize_content').length) {
		$('#customize_content').css('height', _customizeMainHeight).css('max-height', _customizeMainHeight).css('min-height', _customizeMainHeight);
	}

	if ($('#customize_breadcrumbs_search_div').length) {
		$('#customize_breadcrumbs_search_div').css('right', (_mainControlParrentBlockRelativeWidth * -1) + 260);
		$('#cancel_search').css('right', (_mainControlParrentBlockRelativeWidth * -1) + 262);
		$('#search_icon').css('right', (_mainControlParrentBlockRelativeWidth * -1) + 572);
	}
}


function _doFullScreen() {
	var _iframe = parent.document.getElementById('vrnextMainFrame');
	_iframe.allowFullscreen = true;
	_iframe.webkitallowfullscreen = true;
	_iframe.mozallowfullscreen = true;

	if (_iframe.requestFullscreen) {
		_iframe.requestFullscreen();
	} else if (_iframe.mozRequestFullScreen) {
		_iframe.mozRequestFullScreen();
	} else if (_iframe.webkitRequestFullScreen) {
		_iframe.webkitRequestFullScreen();
	} else if (_iframe.msRequestFullscreen) {
		_iframe.msRequestFullscreen();
	}

	$('#full_screen').hide();
	$('#small_screen').show();

	_resizeCustomize(window.parent.screen.availHeight - 30, window.parent.screen.availWidth);
	_resizeCatalogue(window.parent.screen.availHeight - 30, window.parent.screen.availWidth);
}

function _doSmallScreen(_fromKey) {

	if (!_fromKey) {
		var document_parent = parent.document;

		if (document_parent.exitFullscreen) {
			document_parent.exitFullscreen();
		} else if (document_parent.webkitExitFullscreen) {
			document_parent.webkitExitFullscreen();
		} else if (document_parent.mozCancelFullScreen) {
			document_parent.mozCancelFullScreen();
		} else if (document_parent.msExitFullscreen) {
			document_parent.msExitFullscreen();
		}
	}

	$('#full_screen').show();
	$('#small_screen').hide();

	setTimeout(function() {
        var _iframe = parent.document.getElementById('vrnextMainFrame');
		var _iframeHeight = $(_iframe).height() - 75;
		var _iframeWidth  = $(_iframe).width();
		_resizeCustomize(_iframeHeight, _iframeWidth);
		_resizeCatalogue(_iframeHeight, _iframeWidth);
    }, 300);
}

function _fullScreenExitLisener() {
	var _iframe = parent.document.getElementById('vrnextMainFrame');
	$(_iframe).bind('webkitfullscreenchange mozfullscreenchange fullscreenchange', function(e) {
		var _fullscreenElement = parent.document.fullscreenElement || parent.document.mozFullScreenElement || parent.document.webkitFullscreenElement || parent.document.msFullscreenElement;
		if (!_fullscreenElement) {
			_doSmallScreen(true);
		}
	});
}

function _doScreenShot() {
	var screenElement = $('canvas')[0];
	html2canvas(screenElement, {backgroundColor:'#fff'} ).then(function (canvas) {
		_saveScreenShot(canvas.toDataURL('image/png').replace('image/png','image/octet-stream'), 'screen.png');
	});
}

function _saveScreenShot(uri, filename) {

	var link = document.createElement('a');
	if (typeof link.download === 'string') {
		link.href = uri;
		link.download = filename;
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
	} else {
		window.open(uri);
	}
}

function _toolTips(_class) {
	$('.tooltip').remove();
	$('.' + _class).each(function(i){
		_content = $(this).attr('title');
		_content = _replaceAll(_content, '|', '<br>');
		$('body').append("<div class='tooltip' id='tooltip"+ i +"'>"+ _content +"</div>");
		var _toolTip = $('#tooltip'+i);
		$(this).removeAttr("title").mouseover(function(){
			_toolTip.css({opacity:0.8, display:'none'}).fadeIn(0);
		}).mousemove(function(kmouse){
			_toolTip.css({left:kmouse.pageX-290, top:kmouse.pageY-15});
		}).mouseout(function(){
			_toolTip.fadeOut(0);
		});
	});
}

function _getContentToPopup(_for) {

	_startLoading();
	_addContentToPopup('Загрузка');

	var _count_times = 0;
	var _count_times_limit = 10;

	switch(_for) {
		case 'button_help':
			_doGetRequestToJson('button_help', '');
		break;
		case 'button_calc':
			_doGetRequestToJson('button_calc', 'uniq=' + _memberUniq);
		break;
	}

	let _idSetIntervalForResponse = setInterval(() => {
		for (var _content in _lastJsonResponseData) {
			if (_lastJsonResponseData.hasOwnProperty(_for)) {
				_stopLoading();
				clearInterval(_idSetIntervalForResponse);
				_addContentToPopup(_lastJsonResponseData[_content]);
			}
		}

		if (_count_times > _count_times_limit) {
			var error = new Map([
                ['_for', _for]
            ]);
            console.log(error);
            alert('Error found - check console;');
			clearInterval(_idSetIntervalForResponse);
		}
		_count_times++;
	}, 100);

}

function _startLoading() {
	var _loadingWidth = 0;
	$('#loading_main').css('display', 'block');

	window._idSetIntervalForLoading = setInterval(() => {
		if (_loadingWidth > 110) {
			_loadingWidth = 0;
		}
		$('#loading_main_progress').css('width', _loadingWidth + "%");
		_loadingWidth = _loadingWidth + _getRandomFloat(1, 10);
	}, _getRandomFloat(100, 500));

}

function _stopLoading() {
	$('#loading_main').css('display', 'none');
	if (typeof window._idSetIntervalForLoading !== "undefined") {
		clearInterval(window._idSetIntervalForLoading);
	}
}

function _replaceAll(string, search, replace) {
  return string.split(search).join(replace);
}

function _getRandomFloat(min, max) {
	return Math.random() * (max - min) + min;
}

function _addContentToPopup(_content) {
	$('#popup_content').html(_content);
}

function _addContentToCustomize(_content, _to) {
	$('#' + _to).html(_content);
	setTimeout(function() {
		_resizeCustomizeContent();
	},300);
}

function _addContentTo(_content, _to) {
	$('#' + _to).html(_content);
}

function _clearContentToCustomize(_to) {
	$('#' + _to).html('');
}

function _clearContentToPopup() {
	$('#popup_content').html('');
}

function _disableSearch() {
	$('#customize_breadcrumbs_search_input').prop('disabled', true);
}

function _enableSearch() {
	$('#customize_breadcrumbs_search_input').prop('disabled', false);
}

function _delayAjax(ms) {
  var timer = 0;
    return function (callback, scope) {
        clearTimeout (timer);
        timer = setTimeout (function(){
             callback.apply( scope );
        }, ms);
    }
}

function _setMainFrameCss() {
	var _iframe = parent.document.getElementById('vrnextMainFrame');
    _iframe.style.cssText += 'border:0px;';
}

function _addExtraLibs(){

	var style_main = document.createElement('link');
    style_main.rel   = 'stylesheet';
    style_main.type  = 'text/css';
    style_main.href  = _root_script_url + 'src/css/toolbar_main.css';
    document.head.appendChild(style_main);

	setTimeout(function() {
		var style_jquery_ui = document.createElement('link');
		style_jquery_ui.rel   = 'stylesheet';
		style_jquery_ui.type  = 'text/css';
		style_jquery_ui.href  = _root_script_url + 'src/css/jquery-ui.min.css';
		document.head.appendChild(style_jquery_ui);

		var script_htmlToCanvas = document.createElement('script');
		script_htmlToCanvas.src = _root_script_url + 'src/lib/htmlToCanvas.js';
		document.head.appendChild(script_htmlToCanvas);

		var script_jquery_ui = document.createElement('script');
		script_jquery_ui.src = _root_script_url + 'src/lib/jquery-ui.min.js';
		document.head.appendChild(script_jquery_ui);
	}, 500);
}

 function _doGetRequestToJson(_method, _query, _next_func) {
	 _startLoading();

	_lastJsonResponseData = false;
	_lastRequestData      = false;

    $.getJSON(_api_url + _method + '/?' + _query, function(data) {
        if(data.error === false) {
			_stopLoading();
			_lastRequestData      = _query;
			_lastJsonResponseData = data.data_array;
			if (_next_func) {
				 window[_next_func](data.data_array);
			}
        } else {
            var error = new Map([
                ['method', _method],
                ['query', _query],
                ['msg', data.msg]
            ]);
            console.log(error);
            _lastJsonResponseData = false;
			_lastRequestData      = false;
            alert('Error found - check console;');
        }
    });

}


 function _setAjaxProps() {
	$( document ).ready(function() {
	    $.ajaxSetup({
            error: function (jqXHR, exception) {
				if (jqXHR.status === 0) {
						console.log('Not connect. Verify Network.');
				} else if (jqXHR.status == 404) {
						console.log('Requested page not found (404).');
				} else if (jqXHR.status == 500) {
						console.log('Internal Server Error (500).');
				} else if (exception === 'parsererror') {
						console.log('Requested JSON parse failed.');
				} else if (exception === 'timeout') {
						console.log('Time out error.');
				} else if (exception === 'abort') {
						console.log('Ajax request aborted.');
				} else {
						console.log('Uncaught Error. ' + jqXHR.responseText);
				}

				alert('Error found - check console;');
            }
        });
	});
}

function _getUrlParams(_param) {
	var _paramFromUrl = _urlParams[_param];
	if (!!_paramFromUrl) {
		return _paramFromUrl;
	}
	return '';
}

var _urlParams = (function(a) {
	if (a == '') return {};
	var b = {};
	for (var i = 0; i < a.length; ++i)
	{
		var p=a[i].split('=', 2);
		if (p.length == 1)
			b[p[0]] = '';
		else
			b[p[0]] = decodeURIComponent(p[1].replace(/\+/g, ' '));
	}
	return b;
})(window.location.search.substr(1).split('&'));


function _cataloguesProductsModeResult(_data_array) {
	var _productUniq   = '';
	var _query         = '';
	for (var _key in _data_array) {
		for (var _keyIn in _data_array[_key]) {
			if (_keyIn == 'uniq') {
				_productUniq = _data_array[_key][_keyIn];
			}
		}
		_query += "product_uniq[]=" + _productUniq + "&";
	}
	_doGetRequestToJson('catalogues_materials_references_by_product_uniq', _query, '_productCatalogueResult');
}

function _productCatalogueResult(_data_array) {

	var _content       = '';

	var _previewImgSrc = '';
	var _productUniq   = '';
	var _manufactorer  = '';
	var _name 		   = '';
	var _dim_x 		   = '';
	var _dim_y  	   = '';
	var _dim_z 		   = '';
	var _flags 		   = '';
	var _checksum      = '';
	var _checksum_3    = '';
	var _catalogue_uid = '';
	var _article	   = '';
	var _available	   = '';
	var _price	       = '';
	var _units	       = '';
	var _company_uid   = '';

	for (var _key in _data_array) {
		for (var _keyIn in _data_array[_key]) {
			if (_keyIn == 'catalogue_uid') {
				_catalogue_uid = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'product_uniq') {
				_productUniq = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'manufactorer') {
				_manufactorer = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'name') {
				_name = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'dim_x') {
				_dim_x = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'dim_y') {
				_dim_y = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'dim_z') {
				_dim_z = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'flags') {
				_flags = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'checksum') {
				_checksum      = _data_array[_key][_keyIn];
				if (_checksum != null) {
					_checksum_3    = _checksum.substring(0,3);
				}
				_previewImgSrc = _url_to_static + _catalogue_uid + '/' + _checksum_3 + '/' + _checksum + ".jpg";
			}
			if (_keyIn == 'article') {
				_article = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'available') {
				_available = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'price') {
				_price = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'units') {
				_units = _data_array[_key][_keyIn];
			}
			if (_keyIn == 'company_uid') {
				_company_uid = _data_array[_key][_keyIn];
			}
		}
		_content += "<div title='article: "+_article+"|available: "+_available+"|price: "+_price+"|units: "+_units+"|company_uid: "+_company_uid+"|uniq: "+_productUniq+"|manufactorer: "+_manufactorer+"|name: "+_name+"|dim_x: "+_dim_x+"|dim_y: "+_dim_y+"|dim_z: "+_dim_z+"|flags: "+_flags+"' class = '' _productuniq = '" + _productUniq + "' ><img src = '"+ _previewImgSrc +"'/></div>";
		_previewImgSrc = _productUniq = _manufactorer = _name = _dim_x = _dim_y = _dim_z = _flags = _checksum = _checksum_3 =  _article	= _available =  _price = _units = _company_uid = '';

	}

	if (_data_array.length == 0) {
		_content += _getTemplateHtml('no_result');
	}

	_addContentTo(_content, 'catalogue_content');

}
