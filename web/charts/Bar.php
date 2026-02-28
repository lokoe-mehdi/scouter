<?php
namespace Charts;

class Bar extends Chart
{

    private $data;
    private $title;
    private $subtitle;

    public function __construct($title,$data,$subtitle="")
    {
        $this->title = $title;
        $this->data = $data;
        $this->subtitle = $subtitle;
    }

    public function draw($id){
        $html = "
        <div id=\"".$id."\" style=\"min-width: 300px; height: 400px; margin: 0 auto\"></div>
        
        <script type=\"text/javascript\">
        Highcharts.chart('".$id."', {
          chart: {
            type: 'column'
          },
          title: {
            text: '".addslashes($this->title)."'
          },
          subtitle: {
            text: '".addslashes($this->subtitle)."'
          },
          xAxis: {
            type: 'category',
            labels: {
              rotation: -45,
              style: {
                fontSize: '13px',
                fontFamily: 'Verdana, sans-serif'
              }
            }
          },
          yAxis: {
            min: 0,
            title: {
              text: 'Urls'
            }
          },
          legend: {
            enabled: false
          },
          tooltip: {
            pointFormat: '{point.y:.1f}'
          },
          series: [{
            name: 'Population',
            data: [";
            foreach($this->data as $val){
                $html.= "['".$val->label."', ".$val->metric."],";
            }  
            $html.="],
            dataLabels: {
              enabled: true,
              rotation: -90,
              color: '#FFFFFF',
              align: 'right',
              format: '{point.y:.1f}', // one decimal
              y: 10, // 10 pixels down from the top
              style: {
                fontSize: '13px',
                fontFamily: 'Verdana, sans-serif',
                textOutline: 0
              }
            }
          }]
        });
        </script>";
        

        $html="<canvas id=\"$id\"></canvas><script type=\"text/javascript\">var ctx = document.getElementById('$id').getContext('2d');

        var data = {
          labels :[";
          $i=0;
              foreach($this->data as $val){
                if($i>0) { $html.=","; }
                $html.= "'".$val->label."'";
                $i++;
              }
          $html.="],
          datasets:[
            {
              label:'Depth levels',
              data:[";
              $i=0;
              foreach($this->data as $val){
                if($i>0) { $html.=","; }
                $html.= $val->metric;
                $i++;
              }  
              $html.="],
              backgroundColor:'#A63C4F'
            }
          ]
        }
        
        var options = {
          title:{
            display:true,
            text:'Depth levels'
          },
        
          scales:{
            xAxes:[{
              stacked:true
            }],
            yAxes:[{
              stacked:true,
              scaleLabel:{
                display:true,
                labelString:'% pages'
              }
            }]
          },
          animation: {
            duration: 5,
            onComplete: function () {
                // render the value of the chart above the bar
                var ctx = this.chart.ctx;
                ctx.font = Chart.helpers.fontString(Chart.defaults.global.defaultFontSize, 'normal', Chart.defaults.global.defaultFontFamily);
                ctx.fillStyle = '#fff';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'bottom';
                this.data.datasets.forEach(function (dataset) {
                    for (var i = 0; i < dataset.data.length; i++) {
                        var model = dataset._meta[Object.keys(dataset._meta)[0]].data[i]._model;
                        ctx.fillText(dataset.data[i], model.x, model.y + 30);
                    }
                });
            }}
        
        }
        
        var chart_$id = new Chart(ctx,{
          type:'bar',
          data:data,
          options:options
        })</script>";
        echo $html;
    }

}