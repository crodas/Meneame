/**
  Stronglky modified, onky works with DOM2 compatible browsers.
  	Ricardo Galli
  From http://ljouanneau.com/softs/javascript/tooltip.php
 *
 * Can show a tooltip over an element
 * Content of tooltip is the title attribute value of the element
 * copyright 2004 Laurent Jouanneau. http://ljouanneau.com/soft/javascript
 * release under LGPL Licence
 * works with dom2 compliance browser, and IE6. perhaps IE5 or IE4.. not Nestcape 4
 *
 * To use it :
 * 1.include this script on your page
 * 2.insert this element somewhere in your page
 *       <div id="tooltip"></div>
 * 3. style it in your CSS stylesheet (set color, background etc..). You must set
 * this two style too :
 *     div#tooltip { position:absolute; visibility:hidden; ... }
 * 4.the end. test it ! :-)
 *
 */


// create the tooltip object
function tooltip(){}

// setup properties of tooltip object
tooltip.id="tooltip";
tooltip.main=null;
tooltip.offsetx = 10;
tooltip.offsety = 10;
tooltip.shoffsetx = 8;
tooltip.shoffsety = 8;
tooltip.x = 0;
tooltip.y = 0;
tooltip.tooltipShadow=null;
tooltip.tooltipText=null;
tooltip.title_saved='';
tooltip.saveonmouseover=null;
tooltip.timeout = null;
tooltip.active = false;
tooltip.elementInitialWidth = 0;

tooltip.cache = new JSOC();

tooltip.ie = (document.all)? true:false;		// check if ie
if(tooltip.ie) tooltip.ie5 = (navigator.userAgent.indexOf('MSIE 5')>0);
else tooltip.ie5 = false;
tooltip.dom2 = ((document.getElementById) && !(tooltip.ie5))? true:false; // check the W3C DOM level2 compliance. ie4, ie5, ns4 are not dom level2 compliance !! grrrr >:-(




/**
* Open ToolTip. The title attribute of the htmlelement is the text of the tooltip
* Call this method on the mouseover event on your htmlelement
* ex :  <div id="myHtmlElement" onmouseover="tooltip.show(this)"...></div>
*/

tooltip.show = function (event, text) {
      // we save text of title attribute to avoid the showing of tooltip generated by browser
	if (this.dom2  == false ) return false;
	if (this.tooltipShadow == null) {
		this.tooltipShadow = document.createElement("div");
		this.tooltipShadow.setAttribute("id", "tooltip-shadow");
		document.body.appendChild(tooltip.tooltipShadow);

		this.tooltipText = document.createElement("div");
		this.tooltipText.setAttribute("id", "tooltip-text");
		document.body.appendChild(this.tooltipText);
	}
	this.saveonmouseover=document.onmousemove;
	document.onmousemove = this.mouseMove;
	this.elementInitialWidth = this.tooltipText.scrollWidth;
	this.mouseMove(event); // This already moves the div to the right position
	//this.moveTo(this.x + this.offsetx , this.y + this.offsety);

	this.setText(text);
	this.tooltipText.style.visibility ="visible";
	this.tooltipShadow.style.visibility ="visible";
	this.active = true;
	return false;
}


tooltip.setText = function (text) {
	tooltip.tooltipShadow.style.width = 0+"px";
	tooltip.tooltipShadow.style.height = 0+"px";
	this.tooltipText.innerHTML=text;
	setTimeout('tooltip.setShadow()', 1);
	return false;
}

tooltip.setShadow = function () {
	tooltip.tooltipShadow.style.width = tooltip.tooltipText.clientWidth+"px";
	tooltip.tooltipShadow.style.height = tooltip.tooltipText.clientHeight+"px";
}


/**
* hide tooltip
* call this method on the mouseout event of the html element
* ex : <div id="myHtmlElement" ... onmouseout="tooltip.hide(this)"></div>
*/
tooltip.hide = function (event) {
	if (this.dom2  == false) return false;
	document.onmousemove=this.saveonmouseover;
	this.saveonmouseover=null;
	if (this.tooltipShadow != null ) {
		this.tooltipText.style.visibility = "hidden";
		this.tooltipShadow.style.visibility = "hidden";
		this.tooltipText.innerHTML='';
	}
	this.active = false;
}



// Moves the tooltip element
tooltip.mouseMove = function (e) {
   // we don't use "this", but tooltip because this method is assign to an event of document
   // and so is dreferenced

	if (tooltip.ie) {
		tooltip.x = event.clientX;
		tooltip.y = event.clientY;
	} else {
		tooltip.x = e.pageX;
		tooltip.y = e.pageY;
	}
	tooltip.moveTo( tooltip.x +tooltip.offsetx , tooltip.y + tooltip.offsety);
}

// Move the tooltip element
tooltip.moveTo = function (xL,yL) {
	if (this.ie) {
		xL +=  document.documentElement.scrollLeft;
		yL +=  document.documentElement.scrollTop;
	}
	if (this.elementInitialWidth > 0  && document.documentElement.clientWidth > 0 && xL > document.documentElement.clientWidth * 0.6) {
		xL = xL  - this.elementInitialWidth;
	}
	this.tooltipText.style.left = xL +"px";
	this.tooltipText.style.top = yL +"px";
	xLS = xL + this.shoffsetx;
	yLS = yL + this.shoffsety;
	this.tooltipShadow.style.left = xLS +"px";
	this.tooltipShadow.style.top = yLS +"px";
}

// Show the content of a given comment
tooltip.c_show = function (event, type, element) {
      // we save text of title attribute to avoid the showing of tooltip generated by browser
	if (this.dom2  == false ) return false;
	if (type == 'id') {
		target_text = 'comment-' + element;
		target_author = 'cauthor-'+element;
		target = document.getElementById(target_text);
		author_target = document.getElementById(target_author);
		if (! target || ! author_target) return false;
		text = '<strong>'+author_target.innerHTML+'</strong><br/>'+target.innerHTML;
	} else {
		text = element;
	}
	return this.show(event, text);
}


tooltip.clear = function (event) {
	if (this.timeout != null) {
		clearTimeout(this.timeout);
		this.timeout = null;
	}
	this.hide(event);
}

tooltip.ajax_delayed = function (event, script, id, maxcache) {
	maxcache = maxcache || 600000; // 10 minutes in cache
	if (this.active) return false;
	if ((object = this.cache.get(script+id)) != undefined) {
		tooltip.show(event, object[script+id]);
	} else {
		this.show(event, "<?echo _('cargando...');?>");
		this.timeout = setTimeout("tooltip.ajax_request('"+script+"', "+id+", "+maxcache+")", 200);
	}
}

tooltip.ajax_request = function(script, id, maxcache) {
	tooltip.timeout = null;
	var myxmlhttp = new myXMLHttpRequest ();
	var url = base_url + 'backend/'+script+'?id='+id;
	myxmlhttp.open('get', url, true);
	myxmlhttp.onreadystatechange = function () {
		if(myxmlhttp.readyState == 4){
			response = myxmlhttp.responseText;
			if (response.length > 1) {
				tooltip.cache.set(script+id, response, {'ttl':maxcache});
				//tooltip.cache.set(script+id, response, {'ttl':'60000'});
				tooltip.setText(response);
			}
		}
	}
	myxmlhttp.send(null);
}
