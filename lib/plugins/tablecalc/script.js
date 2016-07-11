var tablecalc_table;
var tablecalc_crow;
var tablecalc_ccol;
var tablecalc_labels=new Array();
var tablecalc_defer=new Array();


function tablecalcXY(st) {
	var r=st.match(/r\d+/);
	y=r[0].substr(1)*1;
	var c=st.match(/c\d+/);
	x=c[0].substr(1)*1;
	return new Array(x,y);
}
function tablecalcVal(x,y,table) {
	var v='notset';
	if ((x>=0) && (y>=0)) {
		if (typeof table.rows != 'undefined') {
			if (typeof table.rows[y] != 'undefined') {
				if (typeof table.rows[y].cells[x] != 'undefined') {
					var m=table.rows[y].cells[x].innerHTML;
					//alert("Checking for float: "+stripHTML(m));
					m=parseFloat(stripHTML(m));
					if (!isNaN(m)) {
						v=m;
					} else {
						v="notnum";
					}
				}
			}
		}
	}
	return v;
}

function correctFloat(a) {
	var x=10000000000000;
	return Math.round(a*x)/x;
}

function sum(a) {
	var s=0;
	for (var i=0;i<a.length;i++) {
		s+=a[i]*1;
		s=correctFloat(s);
	}
	return s;
}

function average(a) {
	return correctFloat(sum(a)/a.length);
}

function min(a) {
	var s=a[0];
	for (var i=1;i<a.length;i++) {
		if (a[i]<s) {
			s=a[i];
		}
	}
	return s;
}


function max(a) {
	var s=a[0];
	for (var i=1;i<a.length;i++) {
		if (a[i]>s) {
			s=a[i];
		}
	}
	return s;
}

function label(st) {
	if (typeof tablecalc_labels[st] == 'undefined') {
		tablecalc_labels[st]=tablecalc_table;
	}
	return "";
}

function col() {
	return tablecalc_ccol;
}

function row() {
	return tablecalc_crow;
}

function cell(x,y) {
	var tmp=tablecalcVal(x,y,tablecalc_table);
	if ( (tmp=='notset') || (tmp=='notnum')) {
		return '';
	} else {
		return tmp;
	}
}

function range(x1,y1,x2,y2) {
	var members=new Array();
	for (var x=x1;x<=x2;x++) {
		for (var y=y1;y<=y2;y++) {
			var tmp=cell(x,y);
			if (tmp!='') {
				members[members.length]=tmp;
			}
		}
	}
	var result="";
	if (members.length>0) {
		result="new Array(";
		for (var k=0;k<members.length;k++) {
			if (k) {
				result+=',';
			}
			result+="'"+members[k]+"'";
		}
		result+=")"
	}
	return eval(result);	
}

function count(a) {
	return a.length;
}

function calc() {
	tablecalcProcessDefer();
	return "";
}

function round(num,digits) {
	var d=1;
	for (var i=0;i<digits;i++) {
		d*=10;
	}
	return Math.round(num*d)/d;
}

function nop() {
	return "";
}

function check(condition,whenTrue,whenFalse) {
	if (typeof condition == 'undefined') {
		condition=0;
	}
	if (typeof whenTrue == 'undefined') {
		whenTrue="";
	}
	if (typeof whenFalse == 'undefined') {
		whenFalse="";
	}
	if (condition) {
		return whenTrue;
	} else {
		return whenFalse;
	}
}

function compare(a,b,operation) {
	if (typeof operation == 'undefined') {
		operation='=';
	}
	if (typeof a == 'undefined') {
		a=0;
	}
	if (typeof b == 'undefined') {
		b=0;
	}
	switch (operation) {
		case ">":
			if (a>b) {return 1;} else {return 0;}
			break;
		case "<":
			if (a<b) {return 1;} else {return 0;}
			break;
		case ">=":
			if (a>=b) {return 1;} else {return 0;}
			break;
		case "<=":
			if (a>=b) {return 1;} else {return 0;}
			break;
		case "<>":
		case "!=":
			if (a!=b) {return 1;} else {return 0;}
			break;
		case "=":
		default:
			if (a==b) {return 1;} else {return 0;}
	}
	return 0;
}

function tablecalc(divID, formula, nodefer) {
	var oFormula=formula;
	if (isNaN(nodefer)) {nodefer=0;}
	//alert("Entering: "+divID+"=>"+formula);
	var div = document.getElementById(divID);
	//getting parent TD
	var table=0;
	var cCol=0;
	var cRow=0;
	var pNode=findParentNodeByName(div,"TD");
	if (!pNode) {
		pNode=findParentNodeByName(div,"TH");
	}
	if (pNode) {
		cCol = pNode.cellIndex;
		pNode=findParentNodeByName(pNode,"TR");
		if (pNode) {
			cRow = pNode.rowIndex;
			table=findParentNodeByName(pNode,"TABLE");
		}
	}
	tablecalc_crow=cRow;
	tablecalc_ccol=cCol;
	tablecalc_table=table;

	var matchA=formula.match(/([a-z0-9_]+\.)?(r|c)\d+(r|c)\d+(\:(r|c)\d+(r|c)\d+)?(\,([a-z0-9]+\.)?(r|c)\d+(r|c)\d+(\:(r|c)\d+(r|c)\d+)?){0,99}/g);
	if (matchA != null) {
		for (var i = 0; i<matchA.length; i++) {
			var members=new Array();

			var matchL=matchA[i].split(',');
			for (var j=0;j<matchL.length;j++) {
				var tmp_table=table;
				var matchB=matchL[j].split('.',2);
				if (matchB.length<2) {
					matchB[1]=matchB[0];
				} else {
					if (typeof tablecalc_labels[matchB[0]] != 'undefined') {
						tmp_table=tablecalc_labels[matchB[0]];
					}
				}
				var matchC=matchB[1].split(':',2);
				if (matchC.length<2) {
					matchC[1]=matchC[0];
				}
				from=tablecalcXY(matchC[0]);
				to=tablecalcXY(matchC[1]);
				if (from[0]>to[0]) {
					var tmp=to[0];
					to[0]=from[0];
					from[0]=tmp;
				}
				if (from[1]>to[1]) {
					var tmp=to[1];
					to[1]=from[1];
					from[1]=tmp;
				}			
				for (var fx=from[0];fx<=to[0];fx++) {
					for (var fy=from[1];fy<=to[1];fy++) {
						if ((fx==cCol) && (fy==cRow)) {continue;}
						var tmp=tablecalcVal(fx,fy,tmp_table);
						//alert("member["+fx+","+fy+"]="+tmp);						
						if (tmp == 'notnum') {
							tablecalcAddDefer(divID,oFormula);
							if (!nodefer) {
								tablecalcProcessDefer();
							}							
							return false;
						}
						if (tmp!='notset') {
							members[members.length]=tmp;
						}
					}
				}
			}
			var result="";
			if (members.length>0) {
				if (members.length==1) {
					var tmp=parseFloat(members[0]);
					if (isNaN(tmp)) {
						result="'"+members[0]+"'";
					} else {
						result=members[0]*1;
					}
				} else {
					result="new Array(";
					for (var k=0;k<members.length;k++) {
						if (k) {
							result+=',';
						}
						result+="'"+members[k]+"'";
					}
					result+=")"
				}
			}
			formula=formula.replace(matchA[i],result);
		}
	}
	
	//formula=formula.replace(/\(([a-z0-9_]+)\)/g,"('$1')");
	//alert("Evaluating [nm]: "+formula);
	//formula=formula.replace(/\#([^\(\);,]+)/,"'$1'");
	formula=formula.replace(/;/g,",");	
//	alert("Evaluating: "+formula);
	var rc;
	try {
		eval('calcresult = '+formula);
		div.innerHTML=calcresult;
		//alert("Got result: "+calcresult);
		rc=true;
	} catch (e) {
		rc=false;
		//alert("Exception: "+e);
		tablecalcAddDefer(divID,oFormula);
	}
	if (!nodefer) {
		tablecalcProcessDefer();
	}
	return rc;
}

function tablecalcAddDefer(divID,formula) {
	if (typeof tablecalc_defer[divID] == 'undefined') {
		tablecalc_defer[divID]=formula;
		//alert("Added defer: "+divID+"=>"+tablecalc_defer[divID]);		
	}
}

function tablecalcProcessDefer() {
	var exit=1;
	var steps=0;
	do {
		steps++;
		for (var divID in tablecalc_defer) {
			if (tablecalc_defer[divID].length) {
				//alert("Calling defer: "+divID+"=>"+tablecalc_defer[divID]);
				if (tablecalc(divID,tablecalc_defer[divID],1)) {
					tablecalc_defer[divID]="";
					exit=0;
				}
			}
		}
	} while ( (!exit) && (steps<99) );
}

function findParentNodeByName(pNode,st) {
	while ( ( pNode.nodeName!=st ) && (pNode.parentNode != null) ) {
		pNode=pNode.parentNode;
	}
	if (pNode.nodeName!=st) {
		pNode=0;
	}
	return pNode;
}


function stripHTML(oldString) {
	return oldString.replace(/<[^>]*>/g, "");
}

